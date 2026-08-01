#!/usr/bin/env bash
#
# Encode a static AVIF whose film grain is synthesised by the DECODER.
#
# The grain is not pixels. avifenc writes ~100 bytes of AV1 film-grain parameters into the
# bitstream and the decoder draws the grain when it paints the image. That is the whole point:
# baking noise into the source would wreck compression (noise is incompressible), while the
# parameter set costs nothing and leaves a smooth, cheap image underneath.
#
# Measured on a 1389x1080 lesson background: grain preset 1 added 111 bytes over no grain, and the
# decoded output genuinely differs (RMSE 0.021), so the synthesis really happens on the way out.
#
# AOM only — film-grain-test is an AOM-specific advanced option, so rav1e and svt builds are
# rejected rather than silently encoding a grainless file.

set -euo pipefail

readonly EXIT_USAGE=2
readonly EXIT_MISSING_DEP=3
readonly EXIT_BAD_INPUT=4
readonly EXIT_OUTPUT_EXISTS=5
readonly EXIT_ENCODE_FAILED=6
readonly EXIT_INVALID_OUTPUT=7

QUALITY=65
SPEED=6
GRAIN_PRESET=1
FORCE=0
INPUT=""
OUTPUT=""

usage() {
    cat <<'EOF'
Encode a static AVIF with decoder-synthesised AV1 film grain.

USAGE
    encode-avif-grain.sh [options] <input.png|input.jpg> <output.avif>

OPTIONS
    -q, --quality VALUE    AVIF colour quality, 0..100 (100 = lossless).   Default: 65
    -s, --speed VALUE      AOM encoder speed, 0..10 (lower = slower/better). Default: 6
    -g, --grain VALUE      AOM grain preset, 0..16 (0 = no grain).          Default: 1
    -f, --force            Overwrite the output file if it already exists.
    -h, --help             Print this help.

GRAIN PRESETS
    0        no native film grain — use this for an honest comparison file
    1..16    AOM's built-in film-grain test vectors

    These are DIFFERENT GRAIN MODELS, not a weak-to-strong dial. Pick one by looking at the
    result, not by reaching for the highest number. test-avif-grain-presets.sh renders all
    sixteen from one source so they can be compared side by side.

NOTES
    Grain is applied to the colour planes only ("color:" scope). Alpha is preserved untouched —
    grain on an alpha plane is meaningless and would shimmer the edges of a cut-out.

    Requires avifenc built with AOM (macOS: brew install libavif).
EOF
}

die() {
    local code=$1
    shift
    printf 'error: %s\n' "$*" >&2
    exit "$code"
}

# Integer within an inclusive range, or exit. Rejects "07", "1.5", "-3" and "" alike.
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
        -g | --grain)
            require_option_value "$1" $#
            GRAIN_PRESET=$2
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

    ((${#positionals[@]} != 0)) || die $EXIT_USAGE "no input or output path given. Try --help."
    ((${#positionals[@]} != 1)) || die $EXIT_USAGE "no output path given. Try --help."
    ((${#positionals[@]} == 2)) || die $EXIT_USAGE "expected exactly one input and one output path, got ${#positionals[@]} paths."

    INPUT=${positionals[0]}
    OUTPUT=${positionals[1]}
}

validate_arguments() {
    require_int_in_range "quality" "$QUALITY" 0 100
    require_int_in_range "speed" "$SPEED" 0 10
    require_int_in_range "grain preset" "$GRAIN_PRESET" 0 16

    # tr rather than ${INPUT,,} — macOS still ships Bash 3.2, which has no lowercase expansion.
    local lowered
    lowered=$(printf '%s' "$INPUT" | tr '[:upper:]' '[:lower:]')
    case $lowered in
    *.png | *.jpg | *.jpeg) ;;
    *) die $EXIT_BAD_INPUT "input must be .png, .jpg or .jpeg, got '$INPUT'." ;;
    esac

    [[ -e $INPUT ]] || die $EXIT_BAD_INPUT "input file does not exist: '$INPUT'."
    [[ -f $INPUT ]] || die $EXIT_BAD_INPUT "input is not a regular file: '$INPUT'."
    [[ -r $INPUT ]] || die $EXIT_BAD_INPUT "input file is not readable: '$INPUT'."

    if [[ -e $OUTPUT && $FORCE -eq 0 ]]; then
        die $EXIT_OUTPUT_EXISTS "output already exists: '$OUTPUT'. Pass --force to overwrite."
    fi

    local output_dir
    output_dir=$(dirname -- "$OUTPUT")
    [[ -d $output_dir ]] || die $EXIT_BAD_INPUT "output directory does not exist: '$output_dir'."
}

# avifenc must exist AND be an AOM build. Falling back to rav1e or svt would produce a file that
# looks fine and silently has no grain in it, which is the one failure worth being loud about.
require_avifenc_with_aom() {
    command -v avifenc >/dev/null 2>&1 || die $EXIT_MISSING_DEP \
        "avifenc not found. macOS: brew install libavif. Linux: install your distribution's libavif/libavif-tools package (it must be built with libaom)."

    local probe
    probe=$( (avifenc --version 2>&1 || true) )
    if [[ $probe != *aom* ]]; then
        probe=$( (avifenc --help 2>&1 || true) )
    fi

    [[ $probe == *aom* ]] || die $EXIT_MISSING_DEP \
        "this avifenc build has no AOM encoder, and film-grain-test is an AOM-specific option. Reinstall libavif with libaom support; this script will not fall back to rav1e or svt because that would produce a grainless file without saying so."
}

encode() {
    printf 'encoding %s -> %s (quality %s, speed %s, grain preset %s)\n' \
        "$INPUT" "$OUTPUT" "$QUALITY" "$SPEED" "$GRAIN_PRESET"

    local -a args=(-c aom -q "$QUALITY" -s "$SPEED")

    # Preset 0 means "no grain", so say nothing about grain at all rather than asking for
    # film-grain-test=0 — that keeps the comparison file a genuinely untouched baseline.
    if ((GRAIN_PRESET > 0)); then
        # The "color:" scope matters. Unscoped, the option also reaches the ALPHA encoder, and
        # grain on a transparency mask makes cut-out edges crawl.
        args+=(-a "color:film-grain-test=$GRAIN_PRESET")
    fi

    if ! avifenc "${args[@]}" -- "$INPUT" "$OUTPUT"; then
        die $EXIT_ENCODE_FAILED "avifenc failed to encode '$INPUT'."
    fi
}

verify_output() {
    [[ -f $OUTPUT ]] || die $EXIT_INVALID_OUTPUT "avifenc reported success but '$OUTPUT' does not exist."
    [[ -s $OUTPUT ]] || die $EXIT_INVALID_OUTPUT "avifenc produced an empty file: '$OUTPUT'."

    local kind
    kind=$(file -b -- "$OUTPUT" 2>/dev/null || printf 'unknown')
    case $kind in
    *AVIF* | *ISO\ Media* | *ISO\ Base\ Media*) ;;
    *) printf 'warning: file(1) does not recognise the output as AVIF (%s); check it in a decoder.\n' "$kind" >&2 ;;
    esac

    # A decode is the only proof the bitstream is really readable. Optional: avifdec ships with
    # libavif but a minimal install may not have it, and a missing decoder is not an encode failure.
    if command -v avifdec >/dev/null 2>&1; then
        local probe_dir probe_png
        probe_dir=$(mktemp -d)
        probe_png="$probe_dir/verify.png"
        if avifdec -- "$OUTPUT" "$probe_png" >/dev/null 2>&1; then
            printf 'verified: decodes cleanly\n'
            rm -rf -- "$probe_dir"
        else
            rm -rf -- "$probe_dir"
            die $EXIT_INVALID_OUTPUT "'$OUTPUT' was written but no decoder could read it back."
        fi
    fi

    local bytes
    bytes=$(wc -c <"$OUTPUT" | tr -d ' ')
    printf 'wrote %s (%s bytes)\n' "$OUTPUT" "$bytes"
}

main() {
    parse_args "$@"
    validate_arguments
    require_avifenc_with_aom
    encode
    verify_output
}

main "$@"
