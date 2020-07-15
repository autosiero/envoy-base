# Envoy tools for GitHub

Contains a configuration to deploy your application in a dead-simple
server running on DirectAdmin.

## Installation

It's recommended to add this project as a submodule in the `.tools` (or something)
directory. This documentation assumes the utility is installed in `.tools/envoy`, adjust
where required.

### For Linux and Mac

In a terminal:

```bash
cd "$( git rev-parse --show-toplevel 2>/dev/null )"
test -d .tools || mkdir .tools
git submodule add https://github.com/autosiero/envoy-base.git .tools/envoy
.tools/envoy/install
```

Then edit the `.envoy/*.json` to meet your configuration

### For Windows

Figure it out yourself.

## Adding to GitHub actions

It's recommended to create a separate job after your unit tests, especially
when testing using various php versions.
