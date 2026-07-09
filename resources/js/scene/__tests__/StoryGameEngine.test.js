import { describe, it, expect, vi } from 'vitest'
import { StoryGameEngine } from '../StoryGameEngine.js'

const host = () => document.createElement('div')

// Scene queue fixture: intro, then 3 branch groups (question + option_a + option_b),
// then an outro — mirrors the Setup → Keuze → Gevolg → … story_game spine.
const optionScene = (group, role, label, effects = null) => ({
  kind: 'scene',
  branch_group: group,
  branch_role: role,
  branch_choice_label: label,
  config: effects ? { branch_effects: effects } : null,
})

const questionScene = (group) => ({ kind: 'scene', branch_group: group, branch_role: 'question' })

const buildScenes = () => [
  { kind: 'scene' },                                                            // 0 intro
  questionScene(1),                                                             // 1
  optionScene(1, 'option_a', 'Stuur de cavalerie', { deltas: { morale: 10, troops: -15 }, consequence_line: 'De modder eist zijn tol.' }), // 2
  optionScene(1, 'option_b', 'Wacht tot de grond droogt'),                      // 3
  { kind: 'scene' },                                                            // 4 gevolg
  questionScene(2),                                                             // 5
  optionScene(2, 'option_a', 'Val aan'),                                        // 6
  optionScene(2, 'option_b', 'Trek terug', { deltas: { morale: -80 }, historical_note: 'In werkelijkheid…' }), // 7
  questionScene(3),                                                             // 8
  optionScene(3, 'option_a', 'Onderhandel'),                                    // 9
  optionScene(3, 'option_b', 'Vecht door'),                                     // 10
  { kind: 'scene' },                                                            // 11 slot
]

const METERS = [
  { key: 'morale', label: 'Moreel', icon: 'ti-flame', start: 70 },
  { key: 'troops', label: 'Manschappen', icon: 'ti-users', start: 100 },
]

const makeEngine = (overrides = {}) => {
  const onAdvanceTo = vi.fn()
  const onOverrideContinue = vi.fn()
  const engine = new StoryGameEngine(host(), {
    meters: METERS,
    failThreshold: 0,
    scenes: buildScenes(),
    onAdvanceTo,
    onOverrideContinue,
    ...overrides,
  })
  return { engine, onAdvanceTo, onOverrideContinue }
}

afterEach(() => {
  // The meter HUD is appended to document.body — clean it between tests.
  document.body.innerHTML = ''
  delete window.__storyGame
})

describe('StoryGameEngine.resolveGroups (pure)', () => {
  it('resolves question + 2 options per group across 3 groups', () => {
    const groups = StoryGameEngine.resolveGroups(buildScenes())
    expect(groups.size).toBe(3)

    const g1 = groups.get(1)
    expect(g1.question).toBe(1)
    expect(g1.options.map(o => o.index)).toEqual([2, 3])
    expect(g1.options[0].role).toBe('option_a')
    expect(g1.options[0].label).toBe('Stuur de cavalerie')
    expect(g1.afterIndex).toBe(4) // first scene after the group

    const g3 = groups.get(3)
    expect(g3.question).toBe(8)
    expect(g3.afterIndex).toBe(11)
  })

  it('drops incomplete groups (fewer than 2 options) and ignores non-branch scenes', () => {
    const scenes = [
      { kind: 'scene' },
      questionScene(9),
      optionScene(9, 'option_a', 'Alleen'),
    ]
    const groups = StoryGameEngine.resolveGroups(scenes)
    expect(groups.size).toBe(0)
  })

  it('supports groups without a question scene', () => {
    const scenes = [optionScene(5, 'option_a', 'A'), optionScene(5, 'option_b', 'B')]
    const groups = StoryGameEngine.resolveGroups(scenes)
    expect(groups.get(5).question).toBeNull()
    expect(groups.get(5).afterIndex).toBe(2)
  })
})

describe('StoryGameEngine.computeBeforePlay (pure)', () => {
  const groups = StoryGameEngine.resolveGroups(buildScenes())
  const scenes = buildScenes()

  it('returns null for normal and question scenes', () => {
    expect(StoryGameEngine.computeBeforePlay(groups, {}, 0, scenes[0])).toBeNull()
    expect(StoryGameEngine.computeBeforePlay(groups, {}, 1, scenes[1])).toBeNull()
  })

  it('returns the reconvergence index for a non-chosen option', () => {
    const choices = { 1: { role: 'option_a', optionIndex: 2 } }
    expect(StoryGameEngine.computeBeforePlay(groups, choices, 3, scenes[3])).toBe(4)
  })

  it('returns null for the chosen option (it plays)', () => {
    const choices = { 1: { role: 'option_b', optionIndex: 3 } }
    expect(StoryGameEngine.computeBeforePlay(groups, choices, 3, scenes[3])).toBeNull()
  })

  it("returns 'hold' when an unchosen group is entered at an option", () => {
    expect(StoryGameEngine.computeBeforePlay(groups, {}, 2, scenes[2])).toBe('hold')
  })
})

describe('StoryGameEngine.applyDeltas (pure)', () => {
  it('applies deltas immutably and clamps to 0-100', () => {
    const meters = { morale: 95, troops: 10 }
    const next = StoryGameEngine.applyDeltas(meters, { morale: 20, troops: -25 })
    expect(next).toEqual({ morale: 100, troops: 0 })
    expect(meters).toEqual({ morale: 95, troops: 10 }) // original untouched
  })

  it('ignores unknown keys and non-numeric values', () => {
    const next = StoryGameEngine.applyDeltas({ morale: 50 }, { unknown: -99, morale: 'veel' })
    expect(next).toEqual({ morale: 50 })
  })
})

describe('StoryGameEngine.isGameOver (pure)', () => {
  it('detects a meter at or below the fail threshold', () => {
    expect(StoryGameEngine.isGameOver({ a: 1, b: 50 }, 0)).toBe(false)
    expect(StoryGameEngine.isGameOver({ a: 0, b: 50 }, 0)).toBe(true)
    expect(StoryGameEngine.isGameOver({ a: 10, b: 50 }, 10)).toBe(true)
  })
})

describe('StoryGameEngine DOM + flow', () => {
  it('renders one HUD segment per meter on construction', () => {
    makeEngine()
    const hud = document.body.querySelector('[data-sg-hud]')
    expect(hud).not.toBeNull()
    expect(hud.children.length).toBe(METERS.length)
    expect(hud.textContent).toContain('Moreel')
    expect(hud.textContent).toContain('Manschappen')
  })

  it('shows a choice overlay with two ESCAPED option labels after a question ends', () => {
    const scenes = buildScenes()
    scenes[2].branch_choice_label = '<img src=x onerror=alert(1)>'
    const { engine } = makeEngine({ scenes })

    const took = engine.handleSceneEnd(1, scenes[1])
    expect(took).toBe(true)

    const buttons = engine.host.querySelectorAll('[data-sg-option]')
    expect(buttons.length).toBe(2)
    expect(buttons[0].textContent).toBe('<img src=x onerror=alert(1)>') // literal text…
    expect(buttons[0].querySelector('img')).toBeNull()                  // …never markup
  })

  it('picking an option records the choice and advances to its index (double-click guarded)', () => {
    const scenes = buildScenes()
    const { engine, onAdvanceTo } = makeEngine({ scenes })
    engine.handleSceneEnd(1, scenes[1])

    const optionB = engine.host.querySelector('[data-sg-option="option_b"]')
    optionB.click()
    optionB.click() // rapid double-click → ignored

    expect(onAdvanceTo).toHaveBeenCalledTimes(1)
    expect(onAdvanceTo).toHaveBeenCalledWith(3)
    expect(engine.state.choices).toEqual({ 1: 'option_b' })
  })

  it("beforePlay shows the overlay ('hold') for a question-less group", () => {
    const scenes = [optionScene(5, 'option_a', 'A'), optionScene(5, 'option_b', 'B'), { kind: 'scene' }]
    const { engine } = makeEngine({ scenes })

    expect(engine.beforePlay(0, scenes[0])).toBe('hold')
    expect(engine.host.querySelectorAll('[data-sg-option]').length).toBe(2)
  })

  it('applies deltas after the chosen option ends, then reconverges past the group', () => {
    vi.useFakeTimers()
    const scenes = buildScenes()
    const { engine, onAdvanceTo } = makeEngine({ scenes })

    engine.handleSceneEnd(1, scenes[1])
    engine.host.querySelector('[data-sg-option="option_a"]').click() // → index 2
    onAdvanceTo.mockClear()

    const took = engine.handleSceneEnd(2, scenes[2])
    expect(took).toBe(true)
    expect(engine.state.meters).toEqual({ morale: 80, troops: 85 }) // 70+10, 100−15

    // Consequence caption is up; auto-dismiss then reconverge to afterIndex (4).
    expect(engine.host.querySelector('[data-sg-consequence]')).not.toBeNull()
    vi.runAllTimers()
    expect(onAdvanceTo).toHaveBeenCalledWith(4)
    vi.useRealTimers()
  })

  it('game over at threshold → restart restores the choice-time snapshot and increments restarts', () => {
    const scenes = buildScenes()
    const { engine, onAdvanceTo } = makeEngine({ scenes })

    // Group 2: option_b drops morale by 80 (70 → 0 clamped) → game over.
    engine.handleSceneEnd(5, scenes[5])
    engine.host.querySelector('[data-sg-option="option_b"]').click() // → index 7
    onAdvanceTo.mockClear()

    engine.handleSceneEnd(7, scenes[7]) // no consequence_line → straight to game over
    const interstitial = engine.host.querySelector('[data-sg-gameover]')
    expect(interstitial).not.toBeNull()
    expect(interstitial.textContent).toContain('Zo liep de geschiedenis bijna anders')
    expect(interstitial.textContent).toContain('In werkelijkheid…')
    expect(engine.state.survived).toBe(false)

    engine.host.querySelector('[data-sg-restart]').click()
    expect(engine.state.meters).toEqual({ morale: 70, troops: 100 }) // snapshot restored
    expect(engine.state.restarts).toBe(1)
    expect(engine.state.survived).toBe(true)                          // fresh attempt
    expect(engine.state.choices[2]).toBeUndefined()                   // choice cleared
    expect(onAdvanceTo).toHaveBeenCalledWith(5)                       // back to the question
  })

  it('teacher override on game over calls onOverrideContinue and keeps survived=false', () => {
    const scenes = buildScenes()
    const { engine, onOverrideContinue } = makeEngine({ scenes })

    engine.handleSceneEnd(5, scenes[5])
    engine.host.querySelector('[data-sg-option="option_b"]').click()
    engine.handleSceneEnd(7, scenes[7])

    engine.host.querySelector('[data-sg-override]').click()
    expect(onOverrideContinue).toHaveBeenCalledTimes(1)
    expect(engine.state.survived).toBe(false)
  })

  it('handleSceneEnd returns false for non-branch scenes and absent effects yield no caption', () => {
    vi.useFakeTimers()
    const scenes = buildScenes()
    const { engine, onAdvanceTo } = makeEngine({ scenes })

    expect(engine.handleSceneEnd(0, scenes[0])).toBe(false)

    // Group 3, option_a has NO branch_effects → no deltas, no caption, direct reconverge.
    engine.handleSceneEnd(8, scenes[8])
    engine.host.querySelector('[data-sg-option="option_a"]').click() // → index 9
    onAdvanceTo.mockClear()
    engine.handleSceneEnd(9, scenes[9])
    expect(engine.host.querySelector('[data-sg-consequence]')).toBeNull()
    expect(onAdvanceTo).toHaveBeenCalledWith(11)
    vi.useRealTimers()
  })

  it('showSummaryInto renders picked labels, final meters, survived and restarts; removes the HUD', () => {
    const scenes = buildScenes()
    const { engine } = makeEngine({ scenes })

    engine.handleSceneEnd(1, scenes[1])
    engine.host.querySelector('[data-sg-option="option_a"]').click()

    const target = document.createElement('div')
    engine.showSummaryInto(target)

    const summary = target.querySelector('[data-sg-summary]')
    expect(summary).not.toBeNull()
    expect(summary.textContent).toContain('Stuur de cavalerie')
    expect(summary.textContent).toContain('Overleefd!')
    expect(summary.textContent).toContain('Moreel')
    expect(document.body.querySelector('[data-sg-hud]')).toBeNull() // HUD removed
  })

  it('exposes window.__storyGame and a state snapshot', () => {
    const { engine } = makeEngine()
    expect(window.__storyGame).toBe(engine)
    expect(engine.state).toEqual({
      choices: {},
      meters: { morale: 70, troops: 100 },
      survived: true,
      restarts: 0,
    })
  })
})
