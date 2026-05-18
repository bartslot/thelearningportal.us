# Avatar Processor Setup

Automated pipeline to process Mixamo FBX files into GLB avatars with 52 ARKit shape keys for TTS lip-sync.

## Files

- **`blender_avatar_processor.py`** — Core Blender script (imports FBX, adds shape keys, exports GLB)
- **`process_avatar.sh`** — CLI wrapper (bash script for easy command-line usage)
- **`app/Services/AvatarProcessorService.php`** — Laravel service for backend integration

## Usage

### 1. Command Line (Direct)

```bash
chmod +x process_avatar.sh
./process_avatar.sh /path/to/character.fbx "Character Name" age gender
```

**Example:**
```bash
./process_avatar.sh ~/Downloads/caesar.fbx "Julius Caesar" 62 M
```

**Output:**
- Avatar ID (UUID)
- GLB file at: `public/avatars/{id}/character.glb`
- Metadata at: `public/avatars/{id}/metadata.json`

### 2. From Laravel (Backend)

```php
use App\Services\AvatarProcessorService;

$processor = new AvatarProcessorService();

$avatar = $processor->processAvatar(
    fbxPath: storage_path('uploads/caesar.fbx'),
    characterName: 'Julius Caesar',
    characterAge: 62,
    characterGender: 'M'
);

// $avatar['id']           → UUID
// $avatar['glb_path']     → public/avatars/{id}/character.glb
// $avatar['metadata']     → Metadata array
```

### 3. In Blender Script Editor (Manual)

```python
from blender_avatar_processor import process_avatar

success, avatar_id, output_path, message = process_avatar(
    '/path/to/character.fbx',
    'Character Name',
    62,
    'M'
)
print(message)
```

## Output Structure

```
public/avatars/
└── {avatar_id}/
    ├── character.glb          # GLB with shape keys (52 ARKit blend shapes)
    └── metadata.json          # Character metadata
```

### Metadata JSON Example

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "name": "Julius Caesar",
  "age": 62,
  "gender": "M",
  "glb_file": "character.glb",
  "shape_keys_count": 52,
  "shape_keys": [
    "aa", "ch", "dd", ..., "eyeBlink_R"
  ]
}
```

## Shape Keys (52 ARKit Standard)

**Vowels:** aa, ee, ih, oh, ooh

**Consonants:** ch, dd, ff, gg, jj, kk, ll, mm, nn, pp, rr, ss, tt, th

**Visemes:** PP, FF, TH, DD, kk, nn, sil, aa, ee, ih, oh, ou, er, ah, uh, ao, ae

**Jaw:** open, left, right, forward

**Mouth:** dimple_L/R, stretch_L/R, smile_L/R, frown_L/R, pucker, roll (upper/lower)

**Tongue:** out

**Eyes:** blink_L, blink_R

## Integration with Your System

### In Your Lesson Generation Pipeline

```php
// app/Jobs/GenerateLesson.php

use App\Services\AvatarProcessorService;

public function handle()
{
    $processor = new AvatarProcessorService();
    
    // Upload Mixamo FBX first
    $fbxPath = $this->uploadMixamoFbx($this->lesson->historical_figure);
    
    // Process avatar
    $avatar = $processor->processAvatar(
        $fbxPath,
        $this->lesson->historical_figure_name,
        $this->lesson->historical_figure_age,
        $this->lesson->historical_figure_gender
    );
    
    // Save to lesson
    $this->lesson->update([
        'avatar_id' => $avatar['id'],
        'avatar_path' => $avatar['glb_path'],
    ]);
}
```

### In Your Flutter App

The GLB file with embedded metadata can be loaded directly. Parse metadata from the custom properties:

```dart
// Flutter - Read avatar metadata
var gltfLoader = GLTFLoader();
var gltf = await gltfLoader.load('avatars/{id}/character.glb');

// Shape keys are available as Morphs
var shapeKeys = gltf.scene.getObjectByName('Armature').morphTargetInfluences;

// Metadata is in custom properties (if you store as scene JSON alongside GLB)
```

## Requirements

- **Blender** 3.6+ (with Python API)
- **Python 3.8+**
- **Bash** (for shell script)
- **Write access** to `public/avatars/`

## Troubleshooting

### "FBX file not found"
- Check the path is absolute or correct relative path
- Verify file exists: `ls -la /path/to/file.fbx`

### "No mesh found in FBX"
- Ensure the FBX contains a mesh (not just armature)
- Try importing into Blender manually to verify

### Blender hangs
- Check Blender console for errors: `blender -d`
- Timeout is set to 5 minutes (`timeout: 300`)
- Increase if processing large models

### Permission denied on script
```bash
chmod +x process_avatar.sh
```

## Tips

1. **Batch Processing:** Loop over multiple FBX files
   ```bash
   for fbx in ~/mixamo/*.fbx; do
     ./process_avatar.sh "$fbx" "Character" 30 M
   done
   ```

2. **Store FBX uploads in `storage/uploads/avatars/`** before processing

3. **GLB file is ~10-50MB** depending on mesh complexity. Consider:
   - Vertex count limits
   - Texture baking for production
   - Draco compression (enabled by default)

4. **Test with a single avatar** before automating

## Next Steps

- [ ] Test with a Mixamo character (FBX)
- [ ] Integrate into lesson generation pipeline
- [ ] Set up avatar gallery in teacher dashboard
- [ ] Connect TTS viseme output to shape key animation
