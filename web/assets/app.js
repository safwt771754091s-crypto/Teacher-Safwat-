/* معلمي الذكي اليمني — SPA front-end */
(function () {
    const API = window.API;
    const state = API.state;
    const view = document.getElementById('view');
    const navList = document.getElementById('navList');
    const tabbar = document.getElementById('tabbar');
    const sidenav = document.getElementById('sidenav');
    const overlay = document.getElementById('overlay');
    const toastEl = document.getElementById('toast');

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const el = (id) => document.getElementById(id);

    const platform = {
        owner: { name: 'المعلم صفوت', title: 'معلم المنصة' },
        repo: { name: 'Teacher-Safwat-', url: 'https://github.com/safwt771754091s-crypto/Teacher-Safwat-' },
    };

    function applyBranding() {
        const nameEl = el('ownerName');
        if (nameEl) nameEl.textContent = platform.owner.name;
        const creditEl = el('ownerCredit');
        if (creditEl) creditEl.textContent = platform.owner.name;
        document.querySelectorAll('[data-repo-link]').forEach((a) => {
            a.href = platform.repo.url;
            a.textContent = platform.repo.name;
        });
    }

    function loadConfig() {
        return API.get('/config', { auth: false })
            .then((d) => {
                if (d && d.owner) platform.owner = d.owner;
                if (d && d.repo) platform.repo = d.repo;
            })
            .catch((err) => {
                // A reachable API that fails /config is non-fatal (branding
                // defaults apply), but a network failure must surface so the
                // boot screen can offer a retry.
                if (err && err.isNetworkError) throw err;
            })
            .then(applyBranding);
    }

    function toast(msg, type) {
        toastEl.textContent = msg;
        toastEl.className = 'toast ' + (type || '');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toastEl.classList.add('hidden'), 3200);
    }

    // Online/offline indicator: the installed app keeps working offline, so the
    // user should know when it is serving saved content instead of the network.
    function applyNetworkBadge() {
        const chip = el('netChip');
        if (!chip) return;
        const off = !navigator.onLine;
        chip.textContent = off ? 'بدون إنترنت' : '';
        chip.classList.toggle('hidden', !off);
    }
    window.addEventListener('online', applyNetworkBadge);
    window.addEventListener('offline', applyNetworkBadge);

    function busy(on) {
        document.body.classList.toggle('loading', !!on);
    }

    function closeNav() { sidenav.classList.add('hidden'); overlay.classList.add('hidden'); }

    document.getElementById('menuBtn').onclick = () => {
        sidenav.classList.toggle('hidden');
        overlay.classList.toggle('hidden');
    };
    overlay.onclick = closeNav;

    // ---------------- Auth ----------------
    function authScreen() {
        view.innerHTML = `
        <section class="hero">
            <h1>معلّمي الذكي اليمني</h1>
            <p>معلم منزلي رقمي يشرح لك دروس كتاب المنهج اليمني، يلخّصها، يقرؤها بصوت، ويحلّ التمارين خطوة بخطوة — مع ألعاب تعليمية ممتعة.</p>
            <p class="muted small">بإعداد المعلم <strong id="ownerCredit">المعلم صفوت</strong> · المستودع: <a data-repo-link href="https://github.com/safwt771754091s-crypto/Teacher-Safwat-" target="_blank" rel="noopener">Teacher-Safwat-</a></p>
            <div class="hero-features">
                <span>📖 شرح من الكتاب نفسه</span>
                <span>🔊 استماع للدروس</span>
                <span>✍️ حل خطوة بخطوة</span>
                <span>🎮 ألعاب وشارات</span>
            </div>
        </section>
        <div class="auth-grid">
            <form class="card" id="loginForm">
                <h3>تسجيل الدخول</h3>
                <label>البريد الإلكتروني<input name="email" type="email" required autocomplete="username"></label>
                <label>كلمة المرور<input name="password" type="password" required autocomplete="current-password"></label>
                <button class="btn primary" type="submit">دخول</button>
                <p class="muted small">حسابات تجريبية: owner@school.ye / teacher@school.ye / parent@school.ye / student@school.ye — كلمة المرور: password</p>
            </form>
            <form class="card" id="registerForm">
                <h3>حساب جديد</h3>
                <label>الاسم<input name="name" required></label>
                <label>البريد الإلكتروني<input name="email" type="email" required></label>
                <label>كلمة المرور<input name="password" type="password" required minlength="6"></label>
                <label>نوع الحساب
                    <select name="role">
                        <option value="student">طالب</option>
                        <option value="parent">ولي أمر</option>
                        <option value="teacher">معلم</option>
                    </select>
                </label>
                <label id="gradeWrap">الصف<select name="grade_id" id="gradeSelect"></select></label>
                <button class="btn" type="submit">إنشاء الحساب</button>
            </form>
        </div>`;

        API.get('/grades', { auth: false }).then((d) => {
            el('gradeSelect').innerHTML = d.grades.map((g) => `<option value="${g.id}">${esc(g.name)}</option>`).join('');
        });

        el('loginForm').onsubmit = async (e) => {
            e.preventDefault(); busy(true);
            try {
                const d = await API.post('/auth/login', formToObject(e.target), { auth: false });
                API.setSession(d.user, d.token);
                await showApp();
            } catch (err) { toast(err.message, 'error'); } finally { busy(false); }
        };
        el('registerForm').onsubmit = async (e) => {
            e.preventDefault(); busy(true);
            const f = formToObject(e.target);
            try {
                const d = await API.post('/auth/register', f, { auth: false });
                API.setSession(d.user, d.token);
                await showApp();
            } catch (err) { toast(err.message, 'error'); } finally { busy(false); }
        };
    }

    // FormData -> plain object without Object.fromEntries (ES2019), which is
    // missing on some older phones still in use in the target market.
    function formToObject(form) {
        const out = {};
        new FormData(form).forEach((v, k) => { out[k] = v; });
        return out;
    }

    // ---------------- Role helpers ----------------
    const ROLES = {
        owner: { label: 'المالك / الإدارة', tabs: [['home', 'لوحتي'], ['agents', '🤖 الوكلاء'], ['content', 'إدارة المحتوى'], ['owner', 'الإدارة'], ['profile', 'حسابي']] },
        admin: { label: 'مشرف المحتوى', tabs: [['home', 'لوحتي'], ['agents', '🤖 الوكلاء'], ['content', 'إدارة المحتوى'], ['owner', 'الإدارة'], ['profile', 'حسابي']] },
        teacher: { label: 'معلم', tabs: [['home', 'لوحتي'], ['agents', '🤖 الوكلاء'], ['content', 'إدارة المحتوى'], ['profile', 'حسابي']] },
        parent: { label: 'ولي أمر', tabs: [['home', 'متابعة أبنائي'], ['profile', 'حسابي']] },
        student: { label: 'طالب', tabs: [['school', 'دروسي'], ['games', 'ألعابي'], ['progress', 'تقدمي'], ['profile', 'حسابي']] },
    };

    function renderTabs() {
        const conf = ROLES[state.user.role] || ROLES.student;
        navList.innerHTML = conf.tabs.map(([r, t]) => `<li><a href="#/${r}" data-route="${r}">${t}</a></li>`).join('');
        tabbar.innerHTML = conf.tabs.map(([r, t]) => `<a href="#/${r}" data-route="${r}">${t}</a>`).join('');
        tabbar.classList.remove('hidden');
    }

    function markActive(route) {
        document.querySelectorAll('[data-route]').forEach((a) => a.classList.toggle('active', a.dataset.route === route));
    }

    async function showApp() {
        const u = state.user;
        el('userChip').textContent = u.name;
        el('userChip').classList.remove('hidden');
        el('logoutBtn').classList.remove('hidden');
        el('navUser').textContent = u.name;
        el('navRole').textContent = (ROLES[u.role] || {}).label || u.role;
        el('brandSub').textContent = (ROLES[u.role] || {}).label || '';
        applyBranding();
        renderTabs();
        const route = (location.hash.replace('#/', '') || defaultRoute());
        routeTo(route);
    }

    el('logoutBtn').onclick = () => { API.post('/auth/logout').catch(() => {}); API.logout(); };

    function defaultRoute() {
        const r = state.user.role;
        if (r === 'student') return 'school';
        return 'home';
    }

    // ---------------- Router ----------------
    const routes = {};
    function routeTo(route) {
        closeNav();
        stopSpeaking();
        markActive(route);
        view.scrollTo(0, 0);
        const lessonMatch = /^lesson\/(\d+)/.exec(route);
        const run = lessonMatch
            ? () => lessonScreen(lessonMatch[1])
            : (routes[route] || routes.home || routes.school);
        Promise.resolve(run ? run() : homeScreen()).catch((e) => {
            view.innerHTML = `<div class="card error">تعذّر التحميل: ${esc(e.message)}</div>`;
        });
    }
    window.addEventListener('hashchange', () => routeTo(location.hash.replace('#/', '')));

    // ---------------- Student: subjects & books ----------------
    async function schoolScreen() {
        busy(true);
        const { grades } = await API.get('/grades', { auth: false });
        const myGradeId = state.user.student_profile ? state.user.student_profile.grade_id : null;
        const grade = grades.find((g) => g.id === myGradeId) || grades[0];
        const { subjects } = await API.get(`/grades/${grade.id}/subjects`, { auth: false });
        view.innerHTML = `
        <h2>دروسي — ${esc(grade.name)}</h2>
        <div class="action-row">
            <a class="btn primary" href="${API.API_BASE}/grades/${grade.id}/download?format=pdf" download>⬇️ تحميل كتب الصف كامل (PDF)</a>
        </div>
        <div class="grid cards">
            ${subjects.map((s) => `
                <button class="subject-card" data-subject="${s.id}" style="--c:${esc(s.color || '#2f6a43')}">
                    <span class="subject-ic">${subjectIcon(s.icon)}</span>
                    <strong>${esc(s.name)}</strong>
                    <span class="muted small">${s.textbooks_count} كتاب</span>
                </button>`).join('')}
        </div>
        <div id="booksArea"></div>`;
        document.querySelectorAll('[data-subject]').forEach((b) => {
            b.onclick = () => loadBooks(b.dataset.subject);
        });
        busy(false);
    }

    function subjectIcon(ic) {
        return ({
            book: '📖', mosque: '🕌', text: '🔤', calc: '➗', leaf: '🌿', globe: '🌍',
            atom: '⚛️', flask: '⚗️', palette: '🎨', ball: '⚽', chip: '💻', tools: '🛠️',
            scroll: '🏛️', map: '🗺️', scales: '⚖️', brain: '🧠', think: '🤔',
        })[ic] || '📚';
    }

    async function loadBooks(subjectId) {
        busy(true);
        state.currentSubject = subjectId;
        state.currentGrade = state.user && state.user.student_profile ? state.user.student_profile.grade_id : null;
        const { textbooks } = await API.get(`/subjects/${subjectId}/textbooks`, { auth: false });
        const area = el('booksArea');
        area.innerHTML = `<h3>الكتب</h3><div class="grid cards">` + textbooks.map((t) => {
            const saved = window.Offline && window.Offline.isSaved(t.id);
            return `
            <div class="card">
                <strong>${esc(t.title)}</strong>
                <span class="muted small">الفصل ${t.term === 'first' ? 'الأول' : 'الثاني'} · ${t.lessons_count} درس · ${authorityLabel(t.authority)}</span>
                <div class="action-row">
                    <button class="btn small" data-book="${t.id}">فتح الدروس</button>
                    <a class="btn small" href="${API.API_BASE}/textbooks/${t.id}/download?format=pdf" download>⬇️ تحميل الكتاب كامل</a>
                    <a class="btn small" href="${API.API_BASE}/textbooks/${t.id}/download?format=html" download>⬇️ نسخة بدون إنترنت</a>
                    <button class="btn small ${saved ? 'primary' : ''}" data-save="${t.id}">${saved ? '✅ محفوظ بدون إنترنت' : '📥 حفظ بدون إنترنت'}</button>
                </div>
            </div>`;
        }).join('') + `</div><div id="lessonsArea"></div>`;
        area.scrollIntoView({ behavior: 'smooth' });
        document.querySelectorAll('[data-book]').forEach((b) => {
            b.onclick = () => loadLessons(b.dataset.book);
        });
        document.querySelectorAll('[data-save]').forEach((b) => {
            b.onclick = () => saveBookOffline(b);
        });
        busy(false);
    }

    async function saveBookOffline(btn) {
        if (!window.Offline) return;
        const id = btn.dataset.save;
        if (window.Offline.isSaved(id)) {
            await window.Offline.removeBook(id);
            toast('أُزيل الحفظ بدون إنترنت لهذا الكتاب.');
            loadBooks(state.currentSubject);
            return;
        }
        const original = btn.textContent;
        btn.disabled = true;
        try {
            const res = await window.Offline.saveBook(id, (msg) => { btn.textContent = msg; }, {
                subjectId: state.currentSubject,
                gradeId: state.currentGrade,
            });
            toast(`تم حفظ الكتاب بدون إنترنت (${res.lessons} درس).`);
            btn.textContent = '✅ محفوظ بدون إنترنت';
            btn.classList.add('primary');
        } catch (e) {
            toast(e.message || 'تعذّر الحفظ بدون إنترنت', 'error');
            btn.textContent = original;
        } finally {
            btn.disabled = false;
        }
    }

    async function loadLessons(bookId) {
        busy(true);
        const d = await API.get(`/textbooks/${bookId}/lessons`, { auth: false });
        el('lessonsArea').innerHTML = `<h3>دروس: ${esc(d.textbook.title)}</h3><ul class="lesson-list">` +
            d.lessons.map((l) => `<li><a href="#/lesson/${l.id}" class="lesson-item">
                <span class="lesson-no">${l.sort_order}</span>
                <span class="lesson-title">${esc(l.title)}</span>
                <span class="muted small">ص ${l.page_from == null ? '' : l.page_from}${l.page_to && l.page_to !== l.page_from ? '-' + l.page_to : ''}</span>
            </a></li>`).join('') + `</ul>`;
        el('lessonsArea').scrollIntoView({ behavior: 'smooth' });
        busy(false);
    }

    // ---------------- Lesson view ----------------
    async function lessonScreen(id) {
        busy(true);
        const { lesson } = await API.get(`/lessons/${id}`);
        const sections = lesson.sections || [];
        const exercises = lesson.exercises || [];
        view.innerHTML = `
        <div class="crumbs"><a href="#/school">دروسي</a> › <span>${esc(lesson.title)}</span></div>
        <div class="lesson-head">
            <h2>${esc(lesson.title)}</h2>
            <span class="muted small">${esc(lesson.textbook.subject.name)} — ص ${lesson.page_from == null ? '' : lesson.page_from}</span>
        </div>

        <div class="action-row">
            <button class="btn primary" id="btnExplain">🧠 اشرح لي الدرس</button>
            <button class="btn" id="btnSummary">📝 الملخص</button>
            <button class="btn" id="btnAudio">🔊 استمع للدرس</button>
            <button class="btn" id="btnPages">📖 صفحات الكتاب</button>
        </div>
        <div id="teacherOut" class="teacher-out hidden"></div>

        <h3>نقاط الدرس من الكتاب</h3>
        <ul class="points">
            ${sections.map((s) => `<li class="pt pt-${s.type}">
                ${s.title ? `<strong>${esc(s.title)}</strong>` : ''}
                <span>${esc(s.content)}</span>
                ${s.source_page ? `<span class="page-tag">ص ${s.source_page}</span>` : ''}
            </li>`).join('') || '<li class="muted">لا توجد نقاط بعد.</li>'}
        </ul>

        <h3>التمارين (${exercises.length})</h3>
        <div id="exerciseArea">${exercises.map(renderExercise).join('') || '<p class="muted">لا توجد تمارين.</p>'}</div>

        <div class="card ask-card">
            <h3>اسأل المعلم عن هذا الدرس</h3>
            <div class="ask-row">
                <input id="askInput" placeholder="اكتب سؤالك عن الدرس...">
                <button class="btn" id="askBtn">اسأل</button>
                <button class="btn ghost" id="micBtn" title="سؤال بالصوت">🎙️</button>
            </div>
            <div id="askOut" class="teacher-out hidden"></div>
        </div>`;

        const out = el('teacherOut');
        el('btnExplain').onclick = async () => {
            busy(true);
            try {
                const r = await API.post(`/lessons/${id}/explain`, {});
                renderTeacherOut(out, r, 'شرح الدرس');
                speak(r.explanation.join('. '));
            } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
        el('btnSummary').onclick = async () => {
            busy(true);
            try {
                const { summary } = await API.get(`/lessons/${id}/summary`);
                out.classList.remove('hidden');
                out.innerHTML = `<h4>${esc(summary.title || 'الملخص')}</h4><ul class="points">` +
                    (summary.points || []).map((p) => `<li class="pt">${esc(p)}</li>`).join('') + `</ul>`;
            } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
        el('btnAudio').onclick = async () => {
            busy(true);
            try {
                const r = await API.post(`/lessons/${id}/narrate`, { speed: 1 });
                out.classList.remove('hidden');
                out.innerHTML = `<h4>🔊 قراءة الدرس</h4><p class="muted small">مدة تقريبية: ${r.duration_hint} ثانية</p>`;
                speak(r.text);
            } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
        el('btnPages').onclick = () => openBookPages(lesson);
        el('askBtn').onclick = () => ask(id);
        el('askInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') ask(id); });
        el('micBtn').onclick = () => startVoiceAsk(id);

        bindExercises(exercises);
        trackTime(id);
        busy(false);
    }

    async function ask(lessonId) {
        const q = el('askInput').value.trim();
        if (!q) return;
        busy(true);
        try {
            const r = await API.post(`/lessons/${lessonId}/ask`, { question: q });
            renderTeacherOut(el('askOut'), r, 'جواب المعلم');
            speak(r.explanation.join('. '));
        } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
    }

    function renderTeacherOut(node, r, title) {
        node.classList.remove('hidden');
        node.innerHTML = `<h4>${esc(title)} <span class="badge ${r.provider === 'source' ? '' : 'ai'}">${r.provider === 'source' ? 'من الكتاب' : 'AI موثّق'}</span></h4>
            <ul class="points">${r.explanation.map((x) => `<li class="pt">${esc(x)}</li>`).join('')}</ul>
            ${r.pages && r.pages.length ? `<div class="muted small">المصدر: صفحات ${r.pages.join('، ')} من الكتاب</div>` : ''}
            ${r.needs_content ? '<div class="warn">لم يُعتمد محتوى هذا الدرس بعد.</div>' : ''}`;
    }

    function renderExercise(ex) {
        const opts = (ex.options || []).map((o) => `
            <label class="opt"><input type="radio" name="ex${ex.id}" value="${esc(o.label || o.text)}"><span>${o.label ? esc(o.label) + ') ' : ''}${esc(o.text)}</span></label>`).join('');
        return `<div class="card exercise" data-ex="${ex.id}" data-type="${ex.type}">
            <div class="ex-q"><span class="badge">${typeLabel(ex.type)}</span> ${esc(ex.question)}</div>
            ${ex.type === 'mcq' || ex.type === 'true_false' ? `<div class="opts">${opts}</div>`
                : `<input class="ex-input" placeholder="اكتب إجابتك">`}
            <div class="ex-actions">
                <button class="btn small primary" data-act="submit" data-id="${ex.id}">تحقق</button>
                <button class="btn small ghost" data-act="hint" data-id="${ex.id}">تلميح</button>
                <button class="btn small ghost" data-act="solution" data-id="${ex.id}">الحل خطوة بخطوة</button>
                <button class="btn small ghost" data-act="ai-solution" data-id="${ex.id}">🤖 اشرح لي</button>
            </div>
            <div class="ex-feedback"></div>
        </div>`;
    }

    function typeLabel(t) {
        return ({ mcq: 'اختيار', true_false: 'صح/خطأ', short_answer: 'إجابة قصيرة', step_by_step: 'خطوات', numeric: 'عدد', ordering: 'ترتيب' })[t] || 'سؤال';
    }

    function bindExercises(exercises) {
        view.querySelectorAll('[data-act]').forEach((b) => {
            b.onclick = async () => {
                const id = b.dataset.id;
                const card = b.closest('.exercise');
                const fb = card.querySelector('.ex-feedback');
                busy(true);
                try {
                    if (b.dataset.act === 'submit') {
                        let answer = '';
                        const checked = card.querySelector('input[type=radio]:checked');
                        if (checked) answer = checked.value;
                        else { const inp = card.querySelector('.ex-input'); answer = inp ? inp.value : ''; }
                        const r = await API.post(`/exercises/${id}/submit`, { answer, hint_used: !!card.dataset.hint });
                        fb.className = 'ex-feedback ' + (r.is_correct ? 'ok' : 'bad');
                        fb.innerHTML = `<div>${r.is_correct ? '✅' : '❌'} ${esc(r.feedback)}</div>` +
                            (!r.is_correct && r.expected ? `<div class="muted small">الإجابة الصحيحة: ${esc(r.expected)}</div>` : '');
                    } else if (b.dataset.act === 'hint') {
                        card.dataset.hint = '1';
                        const r = await API.get(`/exercises/${id}/hint`);
                        fb.className = 'ex-feedback hint';
                        fb.innerHTML = `💡 ${esc(r.hint)}`;
                    } else if (b.dataset.act === 'ai-solution') {
                        const r = await API.post(`/exercises/${id}/ai-solution`, {});
                        fb.className = 'ex-feedback steps';
                        const cite = r.source_page ? ` <span class="muted small">(صفحة ${esc(r.source_page)})</span>` : '';
                        fb.innerHTML = `<div class="ai-title">🤖 شرح المعلم الذكي${cite}</div>` +
                            `<ol>${(r.steps || []).map((s) => `<li>${esc(s)}</li>`).join('')}</ol>` +
                            (r.answer ? `<div class="muted small">الإجابة: ${esc(r.answer)}</div>` : '') +
                            (r.needs_content ? `<div class="muted small">هذا السؤال يحتاج محتوى الدرس أولًا.</div>` : '');
                    } else {
                        const r = await API.get(`/exercises/${id}/solution`);
                        fb.className = 'ex-feedback steps';
                        fb.innerHTML = `<ol>${(r.steps || []).map((s) => `<li>${esc(s)}</li>`).join('')}</ol>` +
                            (r.answer ? `<div class="muted small">الإجابة: ${esc(r.answer)}</div>` : '');
                    }
                } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
            };
        });
    }

    let trackedSeconds = 0;
    function trackTime(lessonId) {
        clearInterval(trackTime._t);
        let sec = 0;
        trackTime._t = setInterval(() => {
            sec += 15;
            if (sec >= 60) { sec = 0; API.post(`/lessons/${lessonId}/track`, { seconds: 60 }).catch(() => {}); }
        }, 15000);
    }

    // ---------------- Speech ----------------
    let voiceEnabled = true;
    let voiceWarned = false;

    // Voice lists load asynchronously on Chrome; keep the latest set and pick the
    // best voice for the language (an exact match, else any voice for that locale).
    let voices = [];
    function loadVoices() {
        if (!('speechSynthesis' in window)) return;
        voices = speechSynthesis.getVoices() || [];
    }
    if ('speechSynthesis' in window) {
        loadVoices();
        speechSynthesis.addEventListener('voiceschanged', loadVoices);
    }

    function pickVoice(lang) {
        if (!voices.length) loadVoices();
        const base = lang.split('-')[0];
        return voices.find((v) => v.lang && v.lang.replace('_', '-').toLowerCase() === lang.toLowerCase())
            || voices.find((v) => v.lang && v.lang.toLowerCase().startsWith(base))
            || null;
    }

    // Some devices (notably without an Arabic TTS voice installed) cannot read
    // Arabic aloud. Surface that instead of failing silently.
    function warnNoVoice(lang) {
        if (voiceWarned) return;
        voiceWarned = true;
        toast(lang.startsWith('ar')
            ? 'لا يوجد صوت عربي مثبّت على هذا الجهاز. ثبّت حزمة «تحويل النص إلى كلام» بالعربية من إعدادات الجهاز للاستماع للشرح.'
            : 'No speech voice available on this device.');
    }

    function splitChunks(text) {
        // Split into bilingual chunks so English words are read in English and
        // the Arabic explanation/translation in Arabic. A NUL marker is inserted
        // at sentence/dash/newline boundaries instead of using a regex
        // lookbehind, which is a syntax error on older Safari/WebView.
        const chunks = String(text)
            .replace(/([.!?؟،])\s+/g, '$1\u0000')
            .replace(/\s+—\s+|\n+/g, '\u0000')
            .split('\u0000')
            .map((s) => s.trim())
            .filter(Boolean);
        return chunks.length ? chunks : [String(text)];
    }

    const hasArabic = (s) => /[\u0600-\u06FF]/.test(s);

    // Server-side Arabic TTS fallback: many phones ship without an Arabic voice,
    // so SpeechSynthesis stays silent for Arabic. When that happens we stream
    // the narration from the API's /tts endpoint and play it with <audio>.
    let serverAudio = null;
    let serverAudioCancelled = false;

    function stopServerAudio() {
        serverAudioCancelled = true;
        if (serverAudio) {
            try { serverAudio.pause(); } catch (e) {}
            serverAudio = null;
        }
    }

    function speakViaServer(chunks) {
        stopServerAudio();
        serverAudioCancelled = false;
        let i = 0;
        const next = () => {
            if (serverAudioCancelled || i >= chunks.length) return;
            const chunk = chunks[i++];
            const url = API.API_BASE + '/tts?lang=ar&text=' + encodeURIComponent(chunk);
            const audio = new Audio(url);
            serverAudio = audio;
            audio.onended = next;
            // A chunk that fails to synthesise should not stop the rest.
            audio.onerror = next;
            audio.play().catch(() => { serverAudioCancelled = true; warnNoVoice('ar-SA'); });
        };
        next();
    }

    function speak(text) {
        if (!voiceEnabled || !text) return;
        const chunks = splitChunks(text);
        const arabic = chunks.some(hasArabic);

        if ('speechSynthesis' in window) {
            if (!voices.length) loadVoices();
            // Use the on-device voice when it can read Arabic (free, offline);
            // otherwise hand the Arabic narration to the server.
            if (!arabic || pickVoice('ar-SA')) {
                try {
                    speechSynthesis.cancel();
                    chunks.forEach((chunk) => {
                        const latin = (chunk.match(/[A-Za-z]/g) || []).length;
                        const ar = (chunk.match(/[\u0600-\u06FF]/g) || []).length;
                        const lang = latin > 0 && latin >= ar ? 'en-US' : 'ar-SA';
                        const u = new SpeechSynthesisUtterance(chunk);
                        const voice = pickVoice(lang);
                        if (voice) u.voice = voice;
                        u.lang = lang;
                        u.rate = 0.95;
                        speechSynthesis.speak(u);
                    });
                    return;
                } catch (e) {
                    // fall through to the server voice
                }
            }
        }

        if (arabic) speakViaServer(chunks);
    }

    // Stop any speech currently playing (used by the audio toggle / navigation).
    function stopSpeaking() {
        if ('speechSynthesis' in window) { try { speechSynthesis.cancel(); } catch (e) {} }
        stopServerAudio();
    }

    function startVoiceAsk(lessonId) {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) { toast('المتصفح لا يدعم الإدخال الصوتي، اكتب سؤالك.', 'error'); return; }
        const rec = new SR();
        rec.lang = 'ar-SA';
        rec.interimResults = false;
        rec.onresult = (e) => {
            const q = e.results[0][0].transcript;
            el('askInput').value = q;
            ask(lessonId);
        };
        rec.onerror = () => toast('تعذّر التقاط الصوت.', 'error');
        rec.start();
        toast('تحدث الآن...');
    }

    // ---------------- Student: games ----------------
    routes['games'] = async function gamesScreen() {
        busy(true);
        const { games } = await API.get('/games');
        const { points, earned } = await API.get('/achievements');
        view.innerHTML = `
        <h2>🎮 ألعابي التعليمية</h2>
        <div class="stat-row"><div class="stat"><b>${points}</b><span>نقطة</span></div>
        <div class="stat"><b>${earned.length}</b><span>شارة</span></div></div>
        <div class="grid cards" id="gameGrid">
            ${games.map((g) => `<button class="game-card" data-game='${esc(JSON.stringify(g))}'>
                <span class="game-ic">${gameIcon(g.type)}</span>
                <strong>${esc(g.title)}</strong>
                <span class="muted small">${g.subject ? esc(g.subject.name) + ' · ' : ''}مستوى ${g.difficulty} · ${g.points} نقطة</span>
            </button>`).join('') || '<p class="muted">لا توجد ألعاب.</p>'}
        </div>`;
        view.querySelectorAll('[data-game]').forEach((b) => {
            b.onclick = () => playGame(JSON.parse(b.dataset.game));
        });
        busy(false);
    };

    function gameIcon(t) {
        return ({ math: '➗', science: '🔬', reading: '🔤', sorting: '🧩', memory: '🧠', sequence: '🔢' })[t] || '🎮';
    }

    function playGame(game) {
        const qs = (game.config && game.config.questions) || [];
        let idx = 0, correct = 0, start = Date.now();
        function renderQ() {
            if (idx >= qs.length) return finishGame();
            const q = qs[idx];
            el('modalBody').innerHTML = `<h3>${esc(game.title)}</h3>
                <p class="muted small">سؤال ${idx + 1} من ${qs.length}</p>
                <div class="play-q">${esc(q.q)}</div>
                <div class="play-opts">${q.options.map((o) => `<button class="btn" data-opt="${esc(o)}">${esc(o)}</button>`).join('')}</div>
                <div id="playFb" class="muted"></div>`;
            el('modal').classList.remove('hidden');
            speak(q.q);
            el('modalBody').querySelectorAll('[data-opt]').forEach((b) => {
                b.onclick = () => {
                    const ok = b.dataset.opt === q.answer;
                    if (ok) correct++;
                    el('playFb').innerHTML = ok
                        ? '<span class="ok-text">✅ أحسنت! إجابة صحيحة</span>'
                        : `<span class="bad-text">❌ أخطأت، الإجابة الصحيحة: ${esc(q.answer)}</span>`;
                    speak(ok ? 'أحسنت' : 'أخطأت، حاول مرة أخرى');
                    setTimeout(() => { idx++; renderQ(); }, 900);
                };
            });
        }
        async function finishGame() {
            const dur = Math.round((Date.now() - start) / 1000);
            busy(true);
            try {
                const r = await API.post(`/games/${game.id}/complete`, { correct, total: qs.length, duration_seconds: dur });
                el('modalBody').innerHTML = `<h3>انتهت اللعبة!</h3>
                    <p class="big-score">${correct} / ${qs.length}</p>
                    <p>حصلت على ${r.session.score} نقطة ⭐ ${'★'.repeat(r.session.stars)}${'☆'.repeat(3 - r.session.stars)}</p>
                    <p class="muted">مجموع نقاطك: ${r.points_total}</p>
                    <button class="btn primary" id="closeModal">تم</button>`;
                el('closeModal').onclick = () => { el('modal').classList.add('hidden'); routes['games'](); };
                speak('أحسنت');
            } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        }
        renderQ();
    }
    el('modal').onclick = (e) => { if (e.target.id === 'modal') el('modal').classList.add('hidden'); };

    // ---------------- Book page viewer ----------------
    // Shows the lesson's actual textbook pages (rendered server-side from the
    // book's source PDF) so the student reads the real page, not only the
    // extracted points.
    function openBookPages(lesson) {
        const from = lesson.page_from || 1;
        const to = Math.max(from, lesson.page_to || from);
        let cur = from;

        function show() {
            const url = `${API.API_BASE}/lessons/${lesson.id}/pages/${cur}/image`;
            el('modalBody').innerHTML = `
                <h3>${esc(lesson.title)}</h3>
                <p class="muted small">صفحة ${cur} من ${to} · ${esc(lesson.textbook.subject.name)}</p>
                <div class="page-viewer">
                    <button class="btn ghost" id="pgPrev" ${cur <= from ? 'disabled' : ''}>›</button>
                    <div class="page-frame"><img id="pgImg" class="page-img" alt="صفحة ${cur}" src="${url}">
                        <div id="pgMsg" class="muted small hidden">جارٍ تحميل الصفحة…</div></div>
                    <button class="btn ghost" id="pgNext" ${cur >= to ? 'disabled' : ''}>‹</button>
                </div>
                <div class="action-row center">
                    <a class="btn small" href="${API.API_BASE}/textbooks/${lesson.textbook_id}/download?format=pdf" download>⬇️ تحميل الكتاب كامل</a>
                    <button class="btn primary" id="closeModal">إغلاق</button>
                </div>`;
            el('modal').classList.remove('hidden');

            const img = el('pgImg');
            const msg = el('pgMsg');
            msg.classList.remove('hidden');
            img.onload = () => msg.classList.add('hidden');
            img.onerror = () => {
                img.classList.add('hidden');
                msg.classList.remove('hidden');
                msg.textContent = 'تعذّر عرض هذه الصفحة. حمّل الكتاب كاملًا من الزر أدناه.';
            };

            if (el('pgPrev')) el('pgPrev').onclick = () => { if (cur > from) { cur--; show(); } };
            if (el('pgNext')) el('pgNext').onclick = () => { if (cur < to) { cur++; show(); } };
            el('closeModal').onclick = () => el('modal').classList.add('hidden');
        }
        show();
    }

    // ---------------- Student: progress ----------------
    routes['progress'] = async function progressScreen() {
        busy(true);
        const d = await API.get('/progress');
        view.innerHTML = `
        <h2>📈 تقدمي ونقاط ضعفي</h2>
        <div class="stat-row"><div class="stat"><b>${d.points}</b><span>نقطة</span></div>
        <div class="stat"><b>${d.progress.filter((p) => p.status === 'mastered').length}</b><span>درس متقن</span></div></div>
        <h3>تقدّمي في الدروس</h3>
        <div class="progress-list">
            ${d.progress.map((p) => `<div class="progress-item">
                <span>${esc(p.lesson ? p.lesson.title : '')}</span>
                <div class="bar"><div style="width:${p.mastery_percent}%"></div></div>
                <span class="muted small">${p.mastery_percent}% · ${Math.round(p.time_spent_seconds / 60)} د</span>
            </div>`).join('') || '<p class="muted">لم تبدأ أي درس بعد.</p>'}
        </div>
        <h3>نقاط تحتاج مراجعة</h3>
        <ul class="points">
            ${d.weak_points.map((w) => `<li class="pt">${esc(w.exercise ? w.exercise.question : '')} <span class="muted small">— ${esc(w.exercise && w.exercise.lesson ? w.exercise.lesson.title : '')}</span></li>`).join('') || '<li class="muted">لا يوجد، أحسنت!</li>'}
        </ul>`;
        busy(false);
    };

    // ---------------- Parent ----------------
    async function parentScreen() {
        busy(true);
        const d = await API.get('/parent/dashboard');
        view.innerHTML = `<h2>👨‍👩‍👧 متابعة أبنائي</h2>
        ${d.children.length ? d.children.map(childCard).join('') : `<div class="card"><p>لم تربط أي طالب بعد.</p>
            <div class="ask-row"><input id="linkEmail" placeholder="بريد الطالب الإلكتروني"><button class="btn" id="linkBtn">ربط</button></div></div>`}`;
        const lb = el('linkBtn');
        if (lb) lb.onclick = async () => {
            busy(true);
            try { await API.post('/parent/link-child', { student_email: el('linkEmail').value }); toast('تم الربط'); routes.home(); }
            catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
        busy(false);
    }

    function childCard(c) {
        return `<div class="card child-card">
            <h3>${esc(c.student.user.name)} <span class="muted small">${esc(c.student.grade ? c.student.grade.name : '')}</span></h3>
            <div class="stat-row">
                <div class="stat"><b>${Math.round(c.time_spent_seconds / 60)}</b><span>دقيقة تعلم</span></div>
                <div class="stat"><b>${c.lessons_mastered}</b><span>درس متقن</span></div>
                <div class="stat"><b>${c.mastery_avg}%</b><span>متوسط الإتقان</span></div>
                <div class="stat"><b>${c.games_played}</b><span>لعبة</span></div>
            </div>
            <h4>أخطاء متكررة</h4>
            <ul class="points">${(c.frequent_mistakes || []).map((m) => `<li class="pt">${esc(m.exercise ? m.exercise.question : '')} <span class="muted small">(×${m.c})</span></li>`).join('') || '<li class="muted">لا يوجد</li>'}</ul>
            <h4>توصيات منزلية</h4>
            <ul class="points">${(c.recommendations || []).map((r) => `<li class="pt rec">🏠 ${esc(r)}</li>`).join('')}</ul>
        </div>`;
    }

    // ---------------- Teacher / content management ----------------
    routes['content'] = async function contentScreen() {
        busy(true);
        const { grades } = await API.get('/grades', { auth: false });
        view.innerHTML = `<h2>🛠️ إدارة المحتوى والمناهج</h2>
        <div class="card">
            <h3>اختر الصف والمادة</h3>
            <div class="ask-row">
                <select id="cGrade">${grades.map((g) => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}</select>
                <select id="cSubject"></select>
                <button class="btn" id="cLoad">عرض الكتب</button>
            </div>
        </div>
        <div id="contentArea"></div>`;
        async function loadSubjects() {
            const { subjects } = await API.get(`/grades/${el('cGrade').value}/subjects`, { auth: false });
            el('cSubject').innerHTML = subjects.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
        }
        await loadSubjects();
        el('cGrade').onchange = loadSubjects;
        el('cLoad').onclick = () => loadContentBooks();
        await loadContentBooks();
        busy(false);
    };

    async function loadContentBooks() {
        busy(true);
        const sid = el('cSubject').value;
        const { textbooks } = await API.get(`/subjects/${sid}/textbooks`, { auth: false });
        el('contentArea').innerHTML = `
        <div class="card">
            <h3>إضافة كتاب جديد</h3>
            <div class="ask-row">
                <input id="tbTitle" placeholder="عنوان الكتاب">
                <select id="tbTerm"><option value="first">الفصل الأول</option><option value="second">الفصل الثاني</option></select>
                <select id="tbSource"><option value="pdf">PDF</option><option value="images">صور صفحات</option><option value="manual">إدخال يدوي</option></select>
                <button class="btn primary" id="tbCreate">إنشاء</button>
            </div>
        </div>
        <h3>الكتب</h3>
        ${textbooks.map(bookAdminCard).join('') || '<p class="muted">لا توجد كتب.</p>'}`;
        el('tbCreate').onclick = async () => {
            busy(true);
            try {
                await API.post('/textbooks', {
                    subject_id: Number(sid), title: el('tbTitle').value,
                    term: el('tbTerm').value, source_type: el('tbSource').value,
                });
                toast('تم إنشاء الكتاب'); loadContentBooks();
            } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
        bindBookAdmin();
        busy(false);
    }

    function bookAdminCard(t) {
        return `<div class="card admin-book" data-book="${t.id}">
            <div class="admin-book-head">
                <strong>${esc(t.title)}</strong>
                <span class="badge ${t.status}">${statusLabel(t.status)}</span>
            </div>
            <div class="muted small">${t.lessons_count} درس · ${authorityLabel(t.authority)} · ${t.pages_count || 0} صفحة</div>
            <div class="ex-actions">
                <button class="btn small" data-bact="upload-pdf" data-id="${t.id}">📤 رفع PDF</button>
                <button class="btn small" data-bact="upload-page" data-id="${t.id}">🖼️ رفع صفحة</button>
                <button class="btn small" data-bact="pages" data-id="${t.id}">📄 مراجعة الصفحات</button>
                <button class="btn small" data-bact="draft-lessons" data-id="${t.id}">✂️ توليد مسودات الدروس</button>
                <button class="btn small" data-bact="publish" data-id="${t.id}">✅ نشر</button>
                <button class="btn small ghost" data-bact="lessons" data-id="${t.id}">📚 الدروس</button>
            </div>
            <div class="book-out"></div>
        </div>`;
    }

    function statusLabel(s) {
        return ({ draft: 'مسودة', review: 'قيد المراجعة', published: 'منشور' })[s] || s;
    }

    function authorityLabel(a) {
        return ({ aden: 'منهج عدن', sanaa: 'منهج صنعاء', common: 'منهج وطني' })[a] || '';
    }

    function bindBookAdmin() {
        view.querySelectorAll('[data-bact]').forEach((b) => {
            b.onclick = () => bookAction(b.dataset.bact, b.dataset.id, b.closest('.admin-book'));
        });
    }

    function bookAction(act, id, card) {
        if (act === 'upload-pdf') {
            const inp = document.createElement('input');
            inp.type = 'file'; inp.accept = 'application/pdf';
            inp.onchange = async () => {
                const fd = new FormData(); fd.append('file', inp.files[0]);
                busy(true);
                try { const r = await API.post(`/textbooks/${id}/upload-pdf`, fd); toast(`تم استخراج ${r.pages_extracted} صفحة`); card.querySelector('.book-out').innerHTML = `<div class="warn">راجع النص المستخرج قبل النشر. عدد الصفحات: ${r.pages_extracted}</div>`; }
                catch (e) { toast(e.message, 'error'); } finally { busy(false); }
            };
            inp.click();
        } else if (act === 'upload-page') {
            openPageUpload(id, card);
        } else if (act === 'pages') {
            openPagesReview(id, card);
        } else if (act === 'draft-lessons') {
            busy(true);
            API.post(`/textbooks/${id}/draft-lessons`, {}).then((r) => { toast(`تم إنشاء ${r.lessons_created} درس مسودة`); loadContentBooks(); }).catch((e) => toast(e.message, 'error')).finally(() => busy(false));
        } else if (act === 'publish') {
            busy(true);
            API.post(`/textbooks/${id}/publish`, {}).then(() => { toast('تم النشر'); loadContentBooks(); }).catch((e) => toast(e.message, 'error')).finally(() => busy(false));
        } else if (act === 'lessons') {
            openLessonAdmin(id, card);
        }
    }

    function openPageUpload(bookId, card) {
        el('modalBody').innerHTML = `<h3>رفع صفحة كتاب</h3>
            <label>رقم الصفحة<input id="pgNo" type="number" min="1" value="1"></label>
            <label>صورة الصفحة<input id="pgImg" type="file" accept="image/*"></label>
            <label>النص المستخرج (إن وُجد)<textarea id="pgText" rows="4"></textarea></label>
            <button class="btn primary" id="pgSave">حفظ</button>`;
        el('modal').classList.remove('hidden');
        el('pgSave').onclick = async () => {
            const fd = new FormData();
            fd.append('page_number', el('pgNo').value);
            if (el('pgImg').files[0]) fd.append('image', el('pgImg').files[0]);
            if (el('pgText').value) fd.append('text', el('pgText').value);
            busy(true);
            try { await API.post(`/textbooks/${bookId}/upload-page`, fd); toast('تم حفظ الصفحة'); el('modal').classList.add('hidden'); }
            catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
    }

    async function openPagesReview(bookId, card) {
        busy(true);
        const out = card.querySelector('.book-out');
        try {
            const d = await API.get(`/textbooks/${bookId}/pages`);
            out.innerHTML = `<div class="card">
                <strong>مراجعة الصفحات — ${esc(d.textbook.title)}</strong>
                <div class="muted small">الإجمالي: ${d.counts.total} · خام: ${d.counts.raw} · مستخرج: ${d.counts.extracted} · مُراجَع: ${d.counts.reviewed}</div>
                <ul class="page-review">${d.pages.map((p) => `
                    <li class="page-row">
                        <div class="page-head">صفحة ${p.page_number} <span class="badge ${p.status}">${({ raw: 'خام', extracted: 'مستخدَم/مستخرج', reviewed: 'مُراجَع' })[p.status] || p.status}</span></div>
                        <textarea data-pg="${p.id}" rows="3" class="page-text">${esc(p.extracted_text || '')}</textarea>
                        <button class="btn small primary" data-pgsave="${p.id}">حفظ كمراجَع</button>
                    </li>`).join('') || '<li class="muted">لا توجد صفحات بعد. ارفع PDF أو صورًا أولًا.</li>'}</ul>
            </div>`;
            out.querySelectorAll('[data-pgsave]').forEach((b) => {
                b.onclick = async () => {
                    const ta = out.querySelector(`[data-pg="${b.dataset.pgsave}"]`);
                    busy(true);
                    try {
                        await API.patch(`/pages/${b.dataset.pgsave}`, { extracted_text: ta.value, status: 'reviewed' });
                        toast('تم حفظ الصفحة كمراجَعة');
                    } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
                };
            });
        } catch (e) { toast(e.message, 'error'); }
        busy(false);
    }

    async function openLessonAdmin(bookId, card) {
        busy(true);
        const out = card.querySelector('.book-out');
        const d = await API.get(`/textbooks/${bookId}/lessons`, { auth: false });
        out.innerHTML = `<div class="lesson-admin">
            <div class="ask-row"><input id="nlTitle" placeholder="عنوان درس جديد"><button class="btn small primary" id="nlAdd">إضافة درس</button></div>
            <ul class="lesson-list">${d.lessons.map((l) => `<li class="lesson-item"><span class="lesson-title">${esc(l.title)}</span>
                <button class="btn small ghost" data-sum="${l.id}">توليد ملخص</button>
                <button class="btn small ghost" data-suggest="${l.id}">🤖 اقترح حلولًا</button></li>`).join('')}</ul>
            <div class="summaries-out"></div>
            <div class="suggestions-out"></div>
        </div>`;
        el('nlAdd').onclick = async () => {
            busy(true);
            try { await API.post(`/textbooks/${bookId}/lessons`, { title: el('nlTitle').value }); toast('تمت الإضافة'); openLessonAdmin(bookId, card); }
            catch (e) { toast(e.message, 'error'); } finally { busy(false); }
        };
        const suggestionsOut = out.querySelector('.suggestions-out');
        out.querySelectorAll('[data-suggest]').forEach((b) => {
            b.onclick = async () => {
                busy(true);
                try {
                    const d2 = await API.post(`/lessons/${b.dataset.suggest}/suggest-solutions`, {});
                    if (!d2.count) { toast('لا توجد تمارين بحاجة إلى حل'); return; }
                    suggestionsOut.innerHTML = `<div class="card"><strong>اقتراحات المعلم الذكي (${d2.count})</strong>
                        <p class="muted small">راجع كل اقتراح ثم اعتمده لنشره للطلاب.</p>
                        ${d2.suggestions.map((s) => `<div class="suggestion" data-ex="${s.exercise_id}">
                            <div class="ex-q">${esc(s.question)}</div>
                            <div class="muted small">الثقة: ${esc(s.suggestion.confidence)} · المصدر: ${esc(s.suggestion.provider)}</div>
                            <ol class="points">${(s.suggestion.solution_steps || []).map((x) => `<li class="pt">${esc(x)}</li>`).join('')}</ol>
                            <input class="s-answer" placeholder="الإجابة المرجعية" value="${esc(s.suggestion.answer || '')}">
                            <input class="s-hint" placeholder="تلميح (اختياري)" value="${esc(s.suggestion.hint || '')}">
                            <button class="btn small primary" data-approve-ex="${s.exercise_id}">اعتماد ونشر</button>
                        </div>`).join('')}</div>`;
                    suggestionsOut.querySelectorAll('[data-approve-ex]').forEach((btn) => {
                        btn.onclick = async () => {
                            const box = btn.closest('.suggestion');
                            busy(true);
                            try {
                                await API.post(`/exercises/${btn.dataset.approveEx}/verify`, {
                                    answer: box.querySelector('.s-answer').value,
                                    hint: box.querySelector('.s-hint').value || null,
                                });
                                toast('تم الاعتماد والنشر');
                                box.remove();
                            } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
                        };
                    });
                } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
            };
        });
        const summariesOut = out.querySelector('.summaries-out');
        out.querySelectorAll('[data-sum]').forEach((b) => {
            b.onclick = async () => {
                busy(true);
                try {
                    const { summary } = await API.post(`/lessons/${b.dataset.sum}/generate-summary`, {});
                    summariesOut.insertAdjacentHTML('beforeend',
                        `<div class="card summary-draft"><strong>ملخص مسودة</strong><ul class="points">${(summary.points || []).map((p) => `<li class="pt">${esc(p)}</li>`).join('')}</ul>
                        <button class="btn small primary" data-approve="${summary.id}">اعتماد الملخص</button></div>`);
                    summariesOut.querySelector(`[data-approve="${summary.id}"]`).onclick = async () => {
                        try { await API.post(`/summaries/${summary.id}/approve`, {}); toast('تم الاعتماد'); } catch (e) { toast(e.message, 'error'); }
                    };
                } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
            };
        });
        busy(false);
    }

    // ---------------- AI agents dashboard ----------------
    const AGENT_TARGET = {
        'lesson-explainer': 'lesson',
        'audio-narrator': 'lesson',
        'exercise-solver': 'exercise',
        'content-reviewer': 'lesson',
    };

    routes['agents'] = async function agentsScreen() {
        busy(true);
        try {
            const [cat, tg] = await Promise.all([API.get('/agents'), API.get('/agents/targets')]);
            const r = cat.readiness || {};
            view.innerHTML = `<h2>🤖 وكلاء الذكاء الاصطناعي</h2>
            <div class="card ${r.offline_ready ? '' : 'error'}">
                <h3>جاهزية العمل بدون إنترنت</h3>
                <p>${r.offline_ready ? '✅ المنصة تعمل كاملة بدون إنترنت.' : '⚠️ بعض الوكلاء يحتاج اتصالًا.'}
                   الوكلاء: <b>${r.agents_total}</b> — تعمل بلا إنترنت: <b>${r.agents_offline}</b>
                   — مزوّد السحابة: <b>${esc(r.cloud_provider || 'none')}</b> ${r.cloud_available ? '(متصل)' : '(غير مُفعّل)'}</p>
            </div>
            <div class="card"><h3>الوكلاء</h3><div id="agentsGrid" class="agents-grid"></div></div>
            <div class="card"><h3>سجل التشغيل (آخر 50)</h3><div id="agentRuns"></div></div>`;

            el('agentsGrid').innerHTML = (cat.agents || []).map((a) => {
                const target = AGENT_TARGET[a.key];
                let picker = '';
                if (target === 'lesson') {
                    picker = `<select data-target="${a.key}">${(tg.lessons || []).map((l) => `<option value="${l.id}">${esc(l.title)}</option>`).join('')}</select>`;
                } else if (target === 'exercise') {
                    picker = `<select data-target="${a.key}">${(tg.exercises || []).map((e) => `<option value="${e.id}">${esc(e.title)}</option>`).join('')}</select>`;
                } else if (a.key === 'book-importer') {
                    picker = `<input data-target="${a.key}" type="number" min="0" value="1" title="عدد الكتب" placeholder="عدد الكتب">`;
                }
                return `<div class="agent-card">
                    <div class="agent-head"><b>${esc(a.name)}</b>
                        <span class="chip">${a.offline_capable ? 'يعمل بدون إنترنت' : 'يحتاج اتصالًا'}</span></div>
                    <p class="muted">${esc(a.purpose)}</p>
                    <p class="muted small">تشغيلات: ${a.runs}</p>
                    <div class="agent-run">
                        ${picker}
                        <button class="btn" data-run="${a.key}" data-target-type="${target || ''}">تشغيل</button>
                    </div>
                </div>`;
            }).join('');

            view.querySelectorAll('[data-run]').forEach((b) => {
                b.onclick = () => runAgent(b.dataset.run, b.dataset.targetType);
            });

            await loadAgentRuns();
        } catch (e) { view.innerHTML = `<div class="card error">تعذّر التحميل: ${esc(e.message)}</div>`; }
        busy(false);
    };

    async function runAgent(key, targetType) {
        busy(true);
        try {
            const sel = view.querySelector(`[data-target="${key}"]`);
            const body = { params: {} };
            if (sel && targetType) { body.subject_type = targetType; body.subject_id = Number(sel.value); }
            if (key === 'book-importer' && sel) body.params.limit = Number(sel.value) || 1;
            const d = await API.post(`/agents/${key}/run`, body);
            const res = d.result || {};
            if (res.status === 'needs_content') {
                toast('الوكيل يحتاج محتوى معتمدًا: ' + ((res.output && res.output.reason) || ''), 'error');
            } else if (res.status === 'error') {
                toast('خطأ: ' + (res.error || ''), 'error');
            } else {
                toast(`تم التشغيل (${res.items} عنصر، ${res.offline ? 'بدون إنترنت' : 'سحابي'})`);
            }
            const out = view.querySelector(`[data-out="${key}"]`);
            if (out) out.textContent = JSON.stringify(res.output, null, 2);
            await loadAgentRuns();
        } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
    }

    async function loadAgentRuns() {
        const area = el('agentRuns');
        if (!area) return;
        try {
            const d = await API.get('/agents/runs');
            area.innerHTML = `<table class="table"><thead><tr><th>الوكيل</th><th>الحالة</th><th>الوضع</th><th>عناصر</th><th>مدة (م.ث)</th><th>المستخدم</th></tr></thead><tbody>` +
                (d.runs || []).map((x) => `<tr><td>${esc(x.agent_key)}</td><td>${esc(x.status)}</td>
                    <td>${x.offline ? 'بدون إنترنت' : 'سحابي'}</td><td>${x.items}</td><td>${x.duration_ms}</td>
                    <td>${esc(x.user || '-')}</td></tr>`).join('') + `</tbody></table>`;
        } catch (e) { area.innerHTML = '<p class="muted">تعذّر تحميل السجل</p>'; }
    }

    routes['owner'] = async function ownerScreen() {
        busy(true);
        const o = await API.get('/owner/overview');
        const ai = await API.get('/owner/ai-usage');
        view.innerHTML = `<h2>🏛️ لوحة الإدارة والمالك</h2>
        <div class="stat-row">
            <div class="stat"><b>${o.counts.users}</b><span>مستخدم</span></div>
            <div class="stat"><b>${o.counts.students}</b><span>طالب</span></div>
            <div class="stat"><b>${o.counts.teachers}</b><span>معلم</span></div>
            <div class="stat"><b>${o.counts.lessons}</b><span>درس</span></div>
            <div class="stat"><b>${ai.total_requests}</b><span>طلب AI</span></div>
            <div class="stat"><b>${ai.failed}</b><span>أخطاء</span></div>
        </div>
        <div class="card">
            <h3>المستخدمون</h3>
            <div class="ask-row"><input id="uSearch" placeholder="بحث بالاسم أو البريد"><select id="uRole">
                <option value="">كل الأدوار</option><option value="student">طالب</option><option value="teacher">معلم</option>
                <option value="parent">ولي أمر</option><option value="owner">مالك</option></select>
                <button class="btn" id="uLoadBtn">بحث</button></div>
            <div id="usersArea"></div>
        </div>
        <div class="card"><h3>تكلفة الذكاء الاصطناعي حسب الميزة</h3>
            <ul class="points">${(ai.by_feature || []).map((f) => `<li class="pt">${esc(f.feature)}: ${f.c} طلب — التكلفة ${f.cost || 0}</li>`).join('') || '<li class="muted">لا يوجد استخدام بعد</li>'}</ul>
        </div>`;
        el('uLoadBtn').onclick = loadUsers;
        await loadUsers();
        busy(false);
    };

    async function loadUsers() {
        busy(true);
        try {
            const q = new URLSearchParams();
            if (el('uSearch').value) q.set('search', el('uSearch').value);
            if (el('uRole').value) q.set('role', el('uRole').value);
            const d = await API.get('/owner/users?' + q.toString());
            el('usersArea').innerHTML = `<table class="table"><thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th></th></tr></thead><tbody>` +
                d.users.data.map((u) => `<tr><td>${esc(u.name)}</td><td>${esc(u.email)}</td>
                    <td><select data-role-user="${u.id}">${['student', 'teacher', 'parent', 'owner'].map((r) => `<option value="${r}" ${u.role === r ? 'selected' : ''}>${r}</option>`).join('')}</select></td>
                    <td>${u.status === 'active' ? 'نشط' : 'موقوف'}</td>
                    <td><button class="btn small" data-suspend="${u.id}" data-status="${u.status}">${u.status === 'active' ? 'إيقاف' : 'تنشيط'}</button></td></tr>`).join('') + `</tbody></table>`;
            el('usersArea').querySelectorAll('[data-role-user]').forEach((s) => {
                s.onchange = async () => {
                    try { await API.post('/owner/assign-role', { user_id: Number(s.dataset.roleUser), role: s.value }); toast('تم تحديث الدور'); }
                    catch (e) { toast(e.message, 'error'); }
                };
            });
            el('usersArea').querySelectorAll('[data-suspend]').forEach((b) => {
                b.onclick = async () => {
                    const status = b.dataset.status === 'active' ? 'suspended' : 'active';
                    try { await API.patch(`/owner/users/${b.dataset.suspend}`, { status }); loadUsers(); }
                    catch (e) { toast(e.message, 'error'); }
                };
            });
        } catch (e) { toast(e.message, 'error'); } finally { busy(false); }
    }

    // ---------------- Home / profile ----------------
    routes['home'] = async function homeScreen() {
        const u = state.user;
        if (u.role === 'parent') return parentScreen();
        if (u.role === 'student') return schoolScreen();
        busy(true);
        view.innerHTML = `<h2>لوحة ${esc((ROLES[u.role] || {}).label || '')}</h2>
            <div class="card"><p>مرحبًا ${esc(u.name)} 👋</p>
            <p class="muted">أهلاً بك في منصة «معلمي الذكي اليمني». استخدم القائمة للوصول إلى إدارة المحتوى والمتابعة.</p></div>`;
        if (u.role === 'teacher') {
            try {
                const d = await API.get('/teacher/struggling-lessons');
                view.innerHTML += `<div class="card"><h3>دروس يواجه الطلاب صعوبة فيها</h3>
                    <ul class="points">${d.lessons.map((l) => `<li class="pt">${esc(l.lesson.title)} — ${l.wrong_attempts} خطأ من ${l.students} طالب</li>`).join('') || '<li class="muted">لا توجد بيانات كافية بعد</li>'}</ul></div>`;
            } catch (e) {}
        }
        busy(false);
    };
    routes['school'] = schoolScreen;
    routes['profile'] = async function profileScreen() {
        const u = state.user;
        view.innerHTML = `<h2>حسابي</h2>
        <div class="card">
            <p><strong>الاسم:</strong> ${esc(u.name)}</p>
            <p><strong>البريد:</strong> ${esc(u.email)}</p>
            <p><strong>الدور:</strong> ${esc((ROLES[u.role] || {}).label || u.role)}</p>
            ${u.student_profile && u.student_profile.grade ? `<p><strong>الصف:</strong> ${esc(u.student_profile.grade.name)}</p>` : ''}
            <label class="switch"><input type="checkbox" id="voiceToggle" ${voiceEnabled ? 'checked' : ''}> تفعيل الاستماع الصوتي تلقائيًا</label>
        </div>`;
        el('voiceToggle').onchange = (e) => { voiceEnabled = e.target.checked; };
    };

    // ---------------- Boot ----------------
    // A 401 anywhere in the app drops the local session and returns to login.
    API.setUnauthorizedHandler(() => {
        if (!el('loginForm')) authScreen();
    });

    // Shown when the API cannot be reached at all (server down / no network),
    // so the user sees a clear message instead of a blank page.
    function connectionErrorScreen(err) {
        const hasOffline = window.Offline && Object.keys(window.Offline.list()).length > 0;
        view.innerHTML = `
        <div class="card offline-card">
            <h2>تعذّر الاتصال بالخادم</h2>
            <p class="muted">لم نتمكن من الوصول إلى خدمة المنصة. تحقّق من اتصالك بالإنترنت ثم أعد المحاولة.</p>
            ${hasOffline ? '<p class="muted small">لديك كتب محفوظة بدون إنترنت — افتحها من «دروسي» بعد تسجيل الدخول سابقًا.</p>' : '<p class="muted small">نصيحة: افتح كتابًا واحفظه بزر «📥 حفظ بدون إنترنت» ليعمل لاحقًا بلا شبكة.</p>'}
            <p class="muted small">عنوان الخدمة: <code>${esc(API.API_BASE)}</code></p>
            <button class="btn primary" id="retryBoot">إعادة المحاولة</button>
        </div>`;
        const retry = el('retryBoot');
        if (retry) retry.onclick = () => boot();
    }

    function boot() {
        const bootEl = el('boot');
        if (bootEl) bootEl.classList.remove('hidden');
        applyBranding();
        applyNetworkBadge();
        loadConfig()
            .then(() => {
                if (state.token && state.user) {
                    API.get('/auth/me')
                        .then((d) => { state.user = d.user; showApp(); })
                        .catch((err) => {
                            if (err && err.isNetworkError) {
                                // Offline: keep the cached session so books saved
                                // for offline stay usable instead of dead-ending.
                                showApp();
                            } else {
                                authScreen();
                            }
                        });
                } else {
                    authScreen();
                }
            })
            .catch((err) => connectionErrorScreen(err));
    }

    boot();
})();
