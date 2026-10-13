#!/bin/sh
# Run from the analyzed project's root; keep the native trace outside Mago's captured worker stderr.
set -eu
umask 077
vendor_dir=${MAGO_VENDOR_DIR:-tools/vendor}
if [ ! -d "$vendor_dir" ] && [ -d vendor ]; then vendor_dir=vendor; fi
installer="$vendor_dir/byte-kitsune/mago-doctrine-query-budget/bin/install-native.php"
if [ ! -f "$installer" ]; then
    printf '%s\n' 'Set MAGO_VENDOR_DIR to the Composer vendor directory containing the query-budget extension.' >&2
    exit 2
fi
mago_bin=${MAGO_BIN:-$vendor_dir/bin/mago}
engine_path=$(php "$installer")
trace_path=${MAGO_NATIVE_TRACE_FILE:-"$PWD/mago-native-recursion.log"}
case "$trace_path" in /*) ;; *) trace_path="$PWD/$trace_path" ;; esac
wrapper_dir=$(mktemp -d)
trap 'rm -rf "$wrapper_dir"' EXIT HUP INT TERM
cat > "$wrapper_dir/engine" <<'WRAPPER'
#!/bin/sh
exec "$MAGO_NATIVE_CAPTURE_ENGINE" 2>>"$MAGO_NATIVE_CAPTURE_OUTPUT"
WRAPPER
chmod 700 "$wrapper_dir/engine"
export MAGO_NATIVE_CAPTURE_ENGINE="$engine_path"
export MAGO_NATIVE_CAPTURE_OUTPUT="$trace_path"
export MAGO_NATIVE_ENGINE="$wrapper_dir/engine"
export MAGO_NATIVE_TRACE=1
export MAGO_EXTENSION_BACKEND=rust
if [ "$#" -eq 0 ]; then set -- analyze; fi
printf 'Native recursion trace: %s\n' "$trace_path" >&2
"$mago_bin" "$@"
