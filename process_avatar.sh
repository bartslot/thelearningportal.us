#!/bin/bash

# The Learning Portal - Avatar Processor
# Usage: ./process_avatar.sh /path/to/character.fbx "Character Name" age gender
# Example: ./process_avatar.sh ~/mixamo/caesar.fbx "Julius Caesar" 62 M

set -e

if [ "$#" -ne 4 ]; then
    echo "Usage: $0 <fbx_file> <character_name> <age> <gender>"
    echo "Example: $0 ~/mixamo/caesar.fbx 'Julius Caesar' 62 M"
    exit 1
fi

FBX_FILE="$1"
CHARACTER_NAME="$2"
CHARACTER_AGE="$3"
CHARACTER_GENDER="$4"

# Verify FBX exists
if [ ! -f "$FBX_FILE" ]; then
    echo "ERROR: FBX file not found: $FBX_FILE"
    exit 1
fi

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# Create temporary Python script
TEMP_SCRIPT=$(mktemp)

cat > "$TEMP_SCRIPT" << 'PYTHON_SCRIPT'
import sys
import os

# Add project root to path
sys.path.insert(0, '/Users/bartslot/Projects/thelearningportal.us')

from blender_avatar_processor import process_avatar

fbx_file = sys.argv[1]
character_name = sys.argv[2]
character_age = int(sys.argv[3])
character_gender = sys.argv[4]

success, avatar_id, output_path, message = process_avatar(
    fbx_file,
    character_name,
    character_age,
    character_gender
)

print(message)

sys.exit(0 if success else 1)
PYTHON_SCRIPT

# Run Blender with the script
blender --background --python "$TEMP_SCRIPT" -- "$FBX_FILE" "$CHARACTER_NAME" "$CHARACTER_AGE" "$CHARACTER_GENDER"

EXIT_CODE=$?

# Cleanup
rm "$TEMP_SCRIPT"

exit $EXIT_CODE
