#!/usr/bin/env bash
#
# Render every AOM film-grain preset from one source image so a human can pick by eye.
#
# The presets are sixteen different grain MODELS, not a dial from subtle to heavy, so there is no
# sensible way to choose one programmatically. This writes grain-0 (the untouched baseline)
# alongside grain-1..16 and prints the size of each, and then somebody looks at them.
#
# Look at smooth areas — skies, shadows, gradients, plaster walls. That is where grain reads, and
# also where banding shows up, which is half the reason to want grain on a heavily compressed
# background in the first place.

set -euo pipefail

readonly EXIT_USAGE=2
readonly EXIT_MISSING_DEP=3
readonly EXIT_BAD_INPUT=4
readonly EXIT_OUTPUT_EXISTS=5
readonly EXIT_ENCODE_FAILED=6

readonly MAX_PRESET=16

QUALITY=65
SPEED=6
FORCE=0
INPUT=""
OUTPUT_DIR=""

usage() {
    cat <<'EOF'
Render all AOM film-grain presets from one source image, for visual comparison.

USAGE
    test-avif-grain-presets.sh [options] <input.png|input.jpg> <output-directory>

OPTIONS
    -q, --quality VALUE    AVIF colour quality, 0..100.   Default: 65
    -s, --speed VALUE      AOM encoder speed, 0..10.      Default: 6
    -f, --force            Overwrite existing files in the output directory.
    -h, --help             Print this help.

OUTPUT
    grain-0.avif    no film grain — the baseline to compare against
    grain-1.avif    ..  grain-16.avif

    Each preset is a different grain model. Choose by looking, not by picking the highest number.
    Compare at 100% zoom, and pay attention to skies, shadows and other smooth gradients.

    Requires avifenc built with AOM (macOS: brew install libavif).
EOF
}

die() {
    local code=$1
    shift
    printf 'error: %s\n' "$*" >&2
    exit "$code"
}

require_int_in_range() {
    local label=$1 value=$2 min=$3 max=$4
    [[ $value =~ ^(0|[1-9][0-9]*)$ ]] || die $EXIT_USAGE "$label must be a whole number, got '$value'."
    if ((value < min || value > max)); then
        die $EXIT_USAGE "$label must be between $min and $max, got $value."
    fi
}

require_option_value() {
    local option=$1 count=$2
    ((count >= 2)) || die $EXIT_USAGE "$option needs a value."
}

parse_args() {
    local positionals=()

    while (($# > 0)); do
        case $1 in
        -h | --help)
            usage
            exit 0
            ;;
        -q | --quality)
            require_option_value "$1" $#
            QUALITY=$2
            shift 2
            ;;
        -s | --speed)
            require_option_value "$1" $#
            SPEED=$2
            shift 2
            ;;
        -f | --force)
            FORCE=1
            shift
            ;;
        --)
            shift
            positionals+=("$@")
            break
            ;;
        -*)
            die $EXIT_USAGE "unknown option '$1'. Try --help."
            ;;
        *)
            positionals+=("$1")
            shift
            ;;
        esac
    done

    ((${#positionals[@]} != 0)) || die $EXIT_USAGE "no input or output directory given. Try --help."
    ((${#positionals[@]} != 1)) || die $EXIT_USAGE "no output directory given. Try --help."
    ((${#positionals[@]} == 2)) || die $EXIT_USAGE "expected exactly one input and one output directory, got ${#positionals[@]} paths."

    INPUT=${positionals[0]}
    OUTPUT_DIR=${positionals[1]}
}

validate_arguments() {
    require_int_in_range "quality" "$QUALITY" 0 100
    require_int_in_range "speed" "$SPEED" 0 10

    local lowered
    lowered=$(printf '%s' "$INPUT" | tr '[:upper:]' '[:lower:]')
    case $lowered in
    *.png | *.jpg | *.jpeg) ;;
    *) die $EXIT_BAD_INPUT "input must be .png, .jpg or .jpeg, got '$INPUT'." ;;
    esac

    [[ -e $INPUT ]] || die $EXIT_BAD_INPUT "input file does not exist: '$INPUT'."
    [[ -f $INPUT ]] || die $EXIT_BAD_INPUT "input is not a regular file: '$INPUT'."
    [[ -r $INPUT ]] || die $EXIT_BAD_INPUT "input file is not readable: '$INPUT'."

    if [[ -e $OUTPUT_DIR && ! -d $OUTPUT_DIR ]]; then
        die $EXIT_BAD_INPUT "output path exists and is not a directory: '$OUTPUT_DIR'."
    fi

    if ((FORCE == 0)); then
        local preset candidate
        for ((preset = 0; preset <= MAX_PRESET; preset++)); do
            candidate="$OUTPUT_DIR/grain-$preset.avif"
            # A plain `[[ ... ]] && die` here would leave the loop — and so this function — with a
            # non-zero status on the ordinary path where nothing exists yet, and set -e would end
            # the run with no message at all.
            if [[ -e $candidate ]]; then
                die $EXIT_OUTPUT_EXISTS \
                    "'$candidate' already exists. Pass --force to overwrite, or choose an empty directory."
            fi
        done
    fi
}

require_avifenc_with_aom() {
    command -v avifenc >/dev/null 2>&1 || die $EXIT_MISSING_DEP \
        "avifenc not found. macOS: brew install libavif. Linux: install your distribution's libavif/libavif-tools package (it must be built with libaom)."

    local probe
    probe=$( (avifenc --version 2>&1 || true) )
    if [[ $probe != *aom* ]]; then
        probe=$( (avifenc --help 2>&1 || true) )
    fi

    [[ $probe == *aom* ]] || die $EXIT_MISSING_DEP \
        "this avifenc build has no AOM encoder, and film-grain-test is an AOM-specific option. Reinstall libavif with libaom support."
}

# Encode one preset. Returns non-zero on failure so the caller can keep going and still report a
# non-zero status overall — one bad preset should not hide the fifteen that worked.
encode_preset() {
    local preset=$1
    local output="$OUTPUT_DIR/grain-$preset.avif"
    local -a args=(-c aom -q "$QUALITY" -s "$SPEED")

    if ((preset > 0)); then
        args+=(-a "color:film-grain-test=$preset")
    fi

    if ! avifenc "${args[@]}" -- "$INPUT" "$output" >/dev/null 2>&1; then
        printf '  grain-%-2s FAILED\n' "$preset" >&2
        return 1
    fi

    local bytes
    bytes=$(wc -c <"$output" | tr -d ' ')
    printf '  %-24s %8s bytes%s\n' "grain-$preset.avif" "$bytes" \
        "$( ((preset == 0)) && printf '   (baseline, no grain)' || true)"
}

main() {
    parse_args "$@"
    validate_arguments
    require_avifenc_with_aom

    mkdir -p -- "$OUTPUT_DIR"

    printf 'rendering %s presets from %s (quality %s, speed %s)\n' \
        "$((MAX_PRESET + 1))" "$INPUT" "$QUALITY" "$SPEED"

    local failures=0 preset
    for ((preset = 0; preset <= MAX_PRESET; preset++)); do
        encode_preset "$preset" || failures=$((failures + 1))
    done

    if ((failures > 0)); then
        die $EXIT_ENCODE_FAILED "$failures of $((MAX_PRESET + 1)) presets failed to encode."
    fi

    printf '\nall %s files written to %s\n' "$((MAX_PRESET + 1))" "$OUTPUT_DIR"
    printf 'compare them at 100%% zoom against grain-0.avif, looking at skies, shadows and gradients.\n'
}

main "$@"
