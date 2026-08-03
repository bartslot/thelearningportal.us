// Lazy-loaded bundle for the 3D lesson-scene system (three.js). app.js exposes
// window.loadLessonScene() which dynamically imports this module, so three (~1.7 MB) is only
// downloaded on the lesson-creation wizard — never on the landing page or other app pages.
export { SkyboxSphere } from './SkyboxSphere.js';
export { SceneOverlay } from './SceneOverlay.js';
export { SceneTimelinePlayer } from './SceneTimelinePlayer.js';
export { GameTimerOverlay } from './GameTimerOverlay.js';
export { QuizOverlay } from './QuizOverlay.js';
export { TextOverlayLayer } from './TextOverlayLayer.js';
export { ArtworkOverlay, artObjId, layersSignature } from './ArtworkOverlay.js';
export { AmplitudeWaveform } from './AmplitudeWaveform.js';
export { mountWizardScene } from './wizard-bridge.js';
export { BackgroundMusic, createBackgroundMusic } from './background-music.js';
export { Sfx } from './sfx.js';
