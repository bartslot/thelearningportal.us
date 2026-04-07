#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# start-local.sh — Start all local AI services for The Learning Portal
# Run this in a separate terminal before `composer dev`
# ─────────────────────────────────────────────────────────────────────────────

set -e

echo "🎓 The Learning Portal — Starting local AI services"
echo "────────────────────────────────────────────────────"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PYTHON_BIN="${PYTHON_BIN:-$SCRIPT_DIR/.venv/bin/python3}"
if [ ! -x "$PYTHON_BIN" ]; then
    PYTHON_BIN="python3"
fi

# ── 1. Ollama (LLM) ──────────────────────────────────────────────────────────
if command -v ollama &> /dev/null; then
    echo "🤖 Starting Ollama (LLM) on :11434..."
    ollama serve &> /tmp/ollama.log &
    OLLAMA_PID=$!

    # Pull model if not present
    sleep 2
    if ! ollama list | grep -q "llama3.1:8b"; then
        echo "   Downloading llama3.1:8b (first run — ~5GB)..."
        ollama pull llama3.1:8b
    fi
    echo "   ✅ Ollama running (PID $OLLAMA_PID)"
else
    echo "   ⚠️  Ollama not found. Install: brew install ollama"
fi

# ── 2. Kokoro TTS ─────────────────────────────────────────────────────────────
if "$PYTHON_BIN" -c "import importlib.util, sys; sys.exit(0 if importlib.util.find_spec('kokoro_onnx') or importlib.util.find_spec('kokoro') else 1)" &> /dev/null; then
    echo "🎙  Starting Kokoro TTS on :8880..."
    KOKORO_CMD=""
    if [ -x "$(dirname "$PYTHON_BIN")/kokoro-server" ]; then
        KOKORO_CMD="$(dirname "$PYTHON_BIN")/kokoro-server --port 8880"
    elif command -v kokoro-server &> /dev/null; then
        KOKORO_CMD="kokoro-server --port 8880"
    elif "$PYTHON_BIN" -c "import importlib.util, sys; sys.exit(0 if importlib.util.find_spec('kokoro.server') else 1)" &> /dev/null; then
        KOKORO_CMD="\"$PYTHON_BIN\" -m kokoro.server --port 8880"
    elif "$PYTHON_BIN" -c "import importlib.util, sys; sys.exit(0 if importlib.util.find_spec('kokoro_onnx.server') else 1)" &> /dev/null; then
        KOKORO_CMD="\"$PYTHON_BIN\" -m kokoro_onnx.server --port 8880"
    fi

    if [ -n "$KOKORO_CMD" ]; then
        eval "$KOKORO_CMD" &> /tmp/kokoro.log &
        echo "   ✅ Kokoro TTS launch command started (PID $!)"
    else
        echo "   ⚠️  Kokoro package found, but no server entrypoint detected."
        echo "      Set KOKORO_START_CMD with your server command if needed."
    fi
else
    echo "   ⚠️  Kokoro TTS not found. Install: pip install kokoro-onnx"
fi

# ── 3. SadTalker (Avatar generation) ─────────────────────────────────────────
SADTALKER_DIR="${SADTALKER_DIR:-$HOME/SadTalker}"
if [ -d "$SADTALKER_DIR" ]; then
    echo "🎭 Starting SadTalker on :7860..."

    # Activate the conda 'sadtalker' environment if conda is available
    SADTALKER_PYTHON="python3"
    CONDA_BASE="$(conda info --base 2>/dev/null || true)"
    if [ -n "$CONDA_BASE" ] && [ -f "$CONDA_BASE/etc/profile.d/conda.sh" ]; then
        # shellcheck disable=SC1090
        source "$CONDA_BASE/etc/profile.d/conda.sh"
        conda activate sadtalker 2>/dev/null && SADTALKER_PYTHON="python"
    fi

    (cd "$SADTALKER_DIR" && $SADTALKER_PYTHON app_sadtalker.py --port 7860 &> /tmp/sadtalker.log) &
    echo "   ✅ SadTalker running (PID $!)"
else
    echo "   ⚠️  SadTalker not found at $SADTALKER_DIR"
    echo "   Install: git clone https://github.com/OpenTalker/SadTalker ~/SadTalker"
fi

echo ""
echo "────────────────────────────────────────────────────"
echo "✅ Local AI services started. Now run: composer dev"
echo ""
echo "Service URLs:"
echo "  LLM (Ollama):   http://localhost:11434"
echo "  TTS (Kokoro):   http://localhost:8880"
echo "  Avatar (Sad):   http://localhost:7860"
echo ""
echo "Logs: /tmp/ollama.log | /tmp/kokoro.log | /tmp/sadtalker.log"
echo ""
echo "Press Ctrl+C to stop all services."

# Keep script running
wait
