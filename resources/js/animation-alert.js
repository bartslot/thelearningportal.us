/**
 * animation-alert.js
 *
 * Listens for animation:missingSlot events dispatched by AnimationController
 * when a script tag references a slot with no clips assigned.
 *
 * Shows a role-aware amber toast:
 *   - admin:   lists missing slots + link to Avatar Lab → Movement
 *   - teacher: generic "ask your administrator" message
 *   - student: nothing shown
 *
 * window.lessonMeta must be set in the lesson blade view:
 *   <script>
 *     window.lessonMeta = {
 *       userRole: '{{ auth()->user()->role }}',
 *       avatarLabUrl: '{{ route("admin.avatar-lab", $lesson->avatar) }}?tab=movement'
 *     };
 *   </script>
 */

const missingSlots  = new Set();
let   debounceTimer = null;
let   toastEl       = null;

function init() {
    const { userRole } = window.lessonMeta ?? {};

    // Students never see this alert
    if (!userRole || userRole === 'student') return;

    window.addEventListener('animation:missingSlot', ({ detail }) => {
        missingSlots.add(detail.slot);
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => showToast(userRole), 500);
    });
}

function showToast(userRole) {
    // Remove any existing toast before showing a new one
    dismissToast();

    const { avatarLabUrl } = window.lessonMeta ?? {};
    const slots = [...missingSlots];

    toastEl = document.createElement('div');
    toastEl.setAttribute('role', 'alert');
    toastEl.style.cssText = `
        position: fixed;
        top: 16px;
        right: 16px;
        z-index: 9999;
        max-width: 380px;
        background: #451a03;
        border: 1px solid #92400e;
        border-radius: 12px;
        padding: 14px 16px;
        color: #fde68a;
        font-size: 13px;
        line-height: 1.5;
        box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        display: flex;
        gap: 10px;
        align-items: flex-start;
    `;

    const icon = document.createElement('span');
    icon.textContent = '⚠️';
    icon.style.flexShrink = '0';

    const body = document.createElement('div');
    body.style.flex = '1';

    if (userRole === 'admin') {
        const tagList = slots.map(s => `<code style="background:#78350f;padding:1px 5px;border-radius:4px;">[${s.replace('emotion_', '')}]</code>`).join(', ');
        body.innerHTML = `
            <strong>Some animation tags have no clips assigned:</strong><br>
            ${tagList}<br>
            <a href="${avatarLabUrl ?? '#'}" style="color:#fbbf24;text-decoration:underline;font-size:12px;">
                → Go to Avatar Lab → Movement
            </a>
        `;
    } else {
        // teacher
        body.innerHTML = `
            <strong>Some avatar animations are missing for this lesson.</strong><br>
            <span style="font-size:12px;opacity:0.8;">Ask your administrator to assign them in the Avatar Lab.</span>
        `;
    }

    const dismiss = document.createElement('button');
    dismiss.textContent = '×';
    dismiss.setAttribute('aria-label', 'Dismiss');
    dismiss.style.cssText = `
        background: none;
        border: none;
        color: #fde68a;
        font-size: 18px;
        cursor: pointer;
        padding: 0;
        line-height: 1;
        flex-shrink: 0;
        opacity: 0.7;
    `;
    dismiss.addEventListener('click', dismissToast);

    toastEl.appendChild(icon);
    toastEl.appendChild(body);
    toastEl.appendChild(dismiss);

    document.body.appendChild(toastEl);

    // Auto-dismiss after 10 seconds
    setTimeout(dismissToast, 10_000);
}

function dismissToast() {
    if (toastEl) {
        toastEl.remove();
        toastEl = null;
    }
}

// Auto-initialise when the module is imported
init();
