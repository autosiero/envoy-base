@setup
    // Check required variables
    $required = [
        'remote' => 'clone URL',
        'branch' => 'branch',
    ];

    // Get app root
    define('CODE_ROOT', trim(`git rev-parse --show-toplevel 2>/dev/null`));
    define('TOOL_ROOT', realpath(CODE_ROOT . '/.tools/envoy'));

    // Check required vars
    foreach ($required as $var => $label) {
        if (empty($$var)) {
            throw new Exception("The $label has not been set. Set it using --$var=[value]");
        }
    }

    // Get paths
    $paths = require TOOL_ROOT . '/file-finder.php';

    // Move to root
    chdir($paths['root']);

    // Get branch and tag name
    $originalRef = $branch;
    $branch = preg_match('/([a-z][-_a-z0-9\.]+)$/', $branch, $matches) ? $matches[1] : $branch;
    $tag = preg_match('/^(?:[a-z0-9\-]+\/)*([a-z][-_a-z0-9\.]+)$/', $tag ?? '', $matches) ? $matches[1] : null;

    // Determine file locations
    $environmentConfig = $paths['config']['environment'];
    $storageConfig = $paths['config']['storage'];

    // Get hash if missing
    $hash ??= trim(`git log -1 --format='%H'`);

    // Get tag
    $isTag = isset($tag) && !empty($tag) && preg_match('/^(?:refs\/tags\/)?(v\d+\.\d+\.\d+)$/', $tag, $matches);
    $tag = $isTag ? $matches[1] : null;
    if (!$isTag && preg_match('/^refs?\/tags\/(v\d+\.\d+)(\.\d+)?(\-.+)?$/', $originalRef, $matches)) {
        $isTag = true;
        $tag = $matches[1];
    }

    // If a tag is set, find it's hash
    if ($isTag) {
        $tagRef = escapeshellarg("refs/tags/{$tag}");
        $hash = trim(`git log -1 --format='%H' {$tagRef}`);
        printf("Using commit %s for tag %s\n", substr($tagRef, -8), $tag);
    }

    // Get environments
    $environments = [
        'master' => [
            'name' => 'testing',
            'domain' => 'testing.example.com',
            'env' => 'local'
        ],
        'stable' => [
            'name' => 'acceptance',
            'domain' => 'acceptance.example.com',
            'env' => 'production'
        ],
        '_tagged' => [
            'name' => 'production',
            'domain' => 'production.example.com',
            'env' => 'production'
        ]
    ];

    // Ensure a file exists
    if (!file_exists($environmentConfig)) {
        if (!is_dir(dirname($environmentConfig))) {
            mkdir(dirname($environmentConfig));
        }
        file_put_contents($environmentConfig, json_encode($environments, JSON_PRETTY_PRINT));
    }

    // Get environments
    $environments = json_decode(file_get_contents($environmentConfig), true, 16, JSON_THROW_ON_ERROR);

    // Get proper config
    if ($isTag && empty($environments['_tagged'])) {
            throw new UnderflowException('System is not configured for tagged depoyments');
    } elseif ($isTag) {
        $environment = $environments['_tagged'];
    } else {
        if (!preg_match('/^([a-z][a-z0-9-_]+\/)?[a-z][a-z0-9-_\.]+$/i', $branch)) {
            throw new RuntimeException('Branch seems insecure');
        }
        $environment = $environments[$branch] ?? null;
        if (!$environment) {
            throw new UnderflowException("System is not configured to deploy [$branch]");
        }
    }

    // Set env from branch
    $env = $environment['env'] ?? 'production';
    $domain = $environment['domain'] ?? 'example.com';

    // Settings
    $logFormat = '%h %s (%cr, %cn)'; // see `man git log`

    // Deploy name
    $deployName = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d--H-i-s');

    // Paths
    $domainRoot = "\$HOME/domains/{$domain}";
    $root = "{$domainRoot}/laravel";

    // Get public dir
    $publicDir = "{$domainRoot}/public_html";

    // Get specific paths
    $deployPath = "$root/deployments/{$deployName}";
    $livePath = "$root/live";
    $envPath = "$root/environment/config.env";
    $storagePath = "$root/storage";
    $backupOldPath = "$root/deployments/backup-{$deployName}";
    $branchSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($branch)), '-');

    // Paths that must exist
    $paths = [
        $root,
        dirname($livePath),
        dirname($envPath),
        dirname($backupOldPath),
    ];

    // Storage map
    $storagePathMap = [
        "/assets" => "assets",
        "/content" => "content",
        "/public/assets" => "public-assets",
        "/public/glide-img/containers" => "public-glide-containers",
        "/public/glide-img/paths" => "public-glide-paths",
        "/storage" => "framework",
    ];

    // Ensure a file exists
    if (!file_exists($storageConfig)) {
        if (!is_dir(dirname($storageConfig))) {
        mkdir(dirname($storageConfig));
        }
        file_put_contents($storageConfig, json_encode($storagePathMap, JSON_PRETTY_PRINT));
    }

    // Get environments
    $storagePathMap = json_decode(file_get_contents($storageConfig), true, 16, JSON_THROW_ON_ERROR);
@endsetup

@servers(['web' => 'deploy.local'])

@story('deploy')
    deployment_init
    deployment_clone
    deployment_describe
    deployment_link
    deployment_install
    deployment_build
    deployment_down
    deployment_migrate
    deployment_cache
    deployment_up
    restart_horizon
    deployment_cleanup
    health_check
@endstory

@task('deployment_init')
    {{-- Pre-deploy validation --}}
    echo -e "\nEnsuring working directories exist"
    @foreach ($paths as $path)
    test -d "{{ $path }}" || mkdir -p "{{ $path }}"
    @endforeach

    {{-- Make deployment directory --}}
    echo -e "\nCreating clone path"
    mkdir -p "{{ $deployPath }}"

    if [ -L "{{ $livePath }}" ]; then
        {{-- Report status --}}
        echo -e "\nLive path is currently linked to $( basename "$( realpath "{{ $livePath }}/" )" )"
    elif [ -d "{{ $livePath }}" ]; then
        {{-- Move live directory if it's a normal directory--}}
        echo -e "\nMoving live path to $( basename "{{ $backupOldPath }}" )"
        mv "{{ $livePath }}" "{{ $backupOldPath }}"
        ln -s "{{ $backupOldPath }}" "{{ $livePath }}"
    elif [ ! -L "{{ $livePath }}" ]; then
        {{-- Ensure a directory exists --}}
        echo -e "\nMaking new current and link it to this deploy"
        ln -s "{{ $deployPath }}" "{{ $livePath }}"

        echo -e "\nAlso linking public path"
        rm -rvf "{{ $publicDir }}"
        ln -s "{{ $livePath }}/public" "{{ $publicDir }}"
    fi
@endtask

@task('deployment_clone')
    {{-- Enter deploy repo --}}
    cd "{{ $deployPath }}"

    {{-- Clone repo, but don't checkout yet --}}
    echo -e "\nCloning {{ $remote }} and checking out {{ $branch }}."
    git clone \
        --no-checkout \
        "{{ $remote }}" \
        "{{ $deployPath }}"

    {{-- Check out as branch --}}
    echo -e "\nChecking out {{ $hash }} as 'deployment/{{ $branchSlug }}-{{ $deployName }}'"
    git checkout -b "deployment/{{ $branchSlug }}-{{ $deployName }}" "{{ $hash }}"

    {{-- Init submodules --}}
    echo -e "\nFetching submodules"
    git submodule update --init --force
@endtask

@task('deployment_describe')
    cd "{{ $deployPath }}"
    {{-- Get latest hash of current and active --}}
    NEW_HASH=$( cd "{{ $deployPath }}" && git log -1 --format='%H' )
    OLD_HASH=$( cd "{{ $livePath }}" && git log -1 --format='%H' )

    {{-- Also get log of old version --}}
    NEW_VERSION=$( cd "{{ $deployPath }}" && git log -1 --format="{{ $logFormat }}" )
    OLD_VERSION=$( cd "{{ $livePath }}" && git log -1 --format="{{ $logFormat }}" )

    {{-- Show diff --}}
    echo -e "\n"
    echo "Currently live: ${OLD_VERSION}"
    echo "Currently deploying: ${NEW_VERSION}"
    echo -e "\nChanges since last version:\n"
    git log --decorate --graph --format="{{ $logFormat }}" "${OLD_HASH}..${NEW_HASH}" 2>/dev/null || true
@endtask

@task('deployment_link')
    {{-- Ensure env-file is available --}}
    if [ ! -f "{{ $envPath }}" ]; then
        echo -e "\nCreating new environment config"
        ENV_DIR="$( dirname "{{ $envPath }}" )"
        test -d "$ENV_DIR" || mkdir -p "$ENV_DIR"
        cp -v "{{ $deployPath }}/.env.example" "{{ $envPath }}"
        chmod 0600 "{{ $envPath }}"
    fi

    {{-- Link env-file file --}}
    echo -e "\nLink environment config"
    ln -s "{{ $envPath }}" "{{ $deployPath }}/.env"

    {{-- Ensure a storage root directory exists --}}
    if [ ! -d "{{ $storagePath }}" ]; then
        echo -e "\nCreating new storage directory"
        mkdir -p "{{ $storagePath }}"
    fi

    {{-- Map all files --}}
    @foreach ($storagePathMap as $inDeploy => $inStorage)
    echo "Setting up {{ $inDeploy }} to link with {{ $inStorage }}"

    {{-- Ensure folder and parent folder exists (for pseudo-directories) --}}
    if [ ! -d "{{ $deployPath }}/{{ $inDeploy }}" ]; then
        echo "+ Creating directory in deployment (it's likely created during app run)"
        mkdir -p "{{ $deployPath }}/{{ $inDeploy }}"
    fi

    {{-- Ensure exists --}}
    if [ ! -d "{{ $storagePath }}/{{ $inStorage }}" ]; then
        echo "+ Copying source"
        cp -vr "{{ $deployPath }}/{{ $inDeploy }}" "{{ $storagePath }}/{{ $inStorage }}"
    fi

    {{-- Remove from git --}}
    echo -e "\n+ Removing dir from deployment"
    rm -r "{{ $deployPath }}/{{ $inDeploy }}"

    {{-- Re-link storage --}}
    echo -e "\n+ Re-linking to storage"
    ln -s "{{ $storagePath }}/{{ $inStorage }}" "{{ $deployPath }}/{{ $inDeploy }}"
    @endforeach
@endtask

@task('deployment_install')
    cd "{{ $deployPath }}"

    echo -e "\nInstalling Yarn dependencies"
    yarn \
        --cache-folder="{{ $root }}/cache/node" \
        --frozen-lockfile \
        --link-duplicates \
        --link-folder "{{ $root }}/cache/node-duplicates" \
        --prefer-offline \
        install

    echo -e "\nInstalling Composer dependencies"
    composer \
        --classmap-authoritative \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-suggest \
        install

    {{-- Link public storage --}}
    echo -e "\nLink public directory to storage"
    php "{{ $deployPath }}/artisan" storage:link

    {{-- Generate key if missing --}}
    source "{{ $deployPath }}/.env"
    if [ -z "$APP_KEY" ]; then
        php "{{ $deployPath }}/artisan" key:generate
    fi
@endtask

@task('deployment_build')
    cd "{{ $deployPath }}"

    echo -e "\nBuilding front-end"
    yarn build --no-progress

    echo -e "\nRemoving node_modules"
    rm -rf "{{ $deployPath }}/node_modules"
@endtask

@task('deployment_down')
    cd "{{ $deployPath }}"

    {{-- Stop horizon --}}
    echo -e "\nStopping Laravel Horizon"
    php artisan horizon:terminate --wait || true

    {{-- Pull down new and current app --}}
    echo -e "\nPulling down platform"
    php artisan down --retry=5 || true
    php "{{ $livePath }}/artisan" down --retry=5 || true

    echo -e "\nClearing optimizations"
    php "{{ $livePath }}/artisan" optimize:clear
@endtask

@task('deployment_migrate')
    cd "{{ $deployPath }}"

    {{-- Migrate database --}}
    echo -e "\nMigrating database"
    php artisan migrate --force
@endtask

@task('deployment_cache')
    cd "{{ $deployPath }}"

    {{-- Optimize application --}}
    echo -e "\nOptimizing application"
    php artisan optimize
    php artisan event:cache
@endtask

@task('deployment_up')
    cd "{{ $deployPath }}"

    {{-- Make backlink to current version --}}
    OLD_PATH="$( realpath "{{ $livePath }}/" )"
    ln -s "${OLD_PATH}" "{{ $deployPath }}/_previous"

    {{-- Switch active version --}}
    echo "Switching from $( basename "${OLD_PATH}" ) to $( basename "{{ $deployPath }}" )"
    rm "{{ $livePath }}"
    ln -s "{{ $deployPath }}" "{{ $livePath }}"

    {{-- Start up the server again --}}
    echo -e "\nGoing live"
    php artisan up

    {{-- Get URL --}}
    source .env
    echo -e "\nApplication is live at ${APP_URL}."
    echo ">>URL = ${APP_URL}"
@endtask

@task('deployment_cleanup')
    find "$( dirname "{{ $deployPath }}" )" -maxdepth 1 -name "20*" | sort | head -n -4 | xargs rm -Rf
    echo "Cleaned up old deployments"
@endtask

@story('rollback')
    deployment_rollback
    restart_horizon
    health_check
@endstory

@task('deployment_rollback')
    cd "{{ $root }}"
    if [ ! -L "{{ $livePath }}/_previous" ]; then
        echo "Rollback not supported for this release"
        exit 1
    fi

    if [ ! -e "{{ $livePath }}/_previous/artisan" ]; then
        echo "Previous release has been pruned"
        exit 1
    fi

    OLD_VERSION="$( realpath "{{ $livePath }}/_previous" )"
    if [ "$( realpath "${OLD_PATH}" )" = "$( realpath "${{ $livePath }}" )" ]; then
        echo "Already at latest version"
        exit 1
    fi

    echo -e "\nGoing dark"
    php artisan down --retry=5

    echo -e "\nRolling back to $( basename "${OLD_VERSION}" )"
    rm "{{ $livePath }}"
    ln -s "${OLD_VERSION}" "{{ $livePath }}"

    echo "Re-running caching"
    php artisan optimize:clear
    php artisan optimize
    php artisan event:cache

    echo -e "\nGoing back online"
    php artisan up

    echo -e "\nRolled back to $( basename "${OLD_VERSION}" )"
@endtask

@task('restart_horizon')
    cd "{{ $livePath }}"

    {{-- Un-pause horizon --}}
    echo -e "\nRestarting Laravel Horizon"
    php artisan horizon:continue || true
    php artisan horizon:purge || true

    {{-- Start screen if required --}}
    {{ $livePath }}/resources/bin/start-horizon.sh "{{ $env }}" || true
@endtask

@task('health_check')
    source "{{ $livePath }}/.env"
    echo -e "\nRunning health check..."
    curl --location --fail "${APP_URL}" > /dev/null
@endtask

@story('health_check')
    health_check
@endstory
