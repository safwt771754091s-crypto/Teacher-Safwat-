/* محرّك النماذج التفاعلية — Interactive model engine.
 *
 * Turns a textbook explanation into something the learner manipulates instead
 * of only reading: a draggable point on the complex plane, a tangent line that
 * slides along a curve, a Riemann sum whose rectangles get thinner as n grows,
 * a conic whose focus/directrix can be moved, Pascal's triangle, a probability
 * tree, the unit circle, and vectors in space.
 *
 * A model is described by a small JSON spec that the API returns with each
 * lesson section:
 *     { kind: 'complex-plane', params: {...}, prompt: '…', steps: ['…'] }
 * The server never renders anything; this file owns every pixel, so a new model
 * kind is a new entry in `KINDS` and nothing else.
 *
 * Every model exposes the same shape:
 *     Interactive.render(container, spec, { onChange }) -> { destroy, narrate }
 * `narrate()` returns the explanation lines so the page can speak them with the
 * Arabic teacher voice (window.speak), which is how the teacher "explains" the
 * figure she is pointing at.
 *
 * Geometry note: all drawing happens in *math* coordinates and is mapped to the
 * canvas by each model's own `toPx`, so shapes stay correct at any size and the
 * canvas can be resized or printed without distortion.
 */
(function () {
    'use strict';

    var DPR = Math.min(window.devicePixelRatio || 1, 2);
    var INK = '#1f2937';
    var ACCENT = '#0f766e';
    var ACCENT2 = '#b45309';
    var GRID = '#e5e7eb';
    var AXIS = '#94a3b8';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    // Fit a canvas to its container at device resolution and hand back a 2-D
    // context already scaled so 1 unit == 1 CSS pixel.
    function setupCanvas(container, height) {
        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%';
        canvas.style.height = height + 'px';
        canvas.setAttribute('role', 'img');
        container.appendChild(canvas);
        function resize() {
            var w = container.clientWidth || 320;
            canvas.width = Math.round(w * DPR);
            canvas.height = Math.round(height * DPR);
            var ctx = canvas.getContext('2d');
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            if (canvas._draw) canvas._draw(w, height);
        }
        var ro = null;
        if (window.ResizeObserver) {
            ro = new ResizeObserver(resize);
            ro.observe(container);
        } else {
            window.addEventListener('resize', resize);
        }
        resize();
        return { canvas: canvas, ctx: canvas.getContext('2d'), resize: resize, ro: ro };
    }

    // Attach pointer drag helpers. `toMath(px, py)` converts a canvas point to
    // model coordinates; `onDrag(x, y, phase)` gets 'start' | 'move' | 'end'.
    function draggable(canvas, toMath, onDrag) {
        var active = false;
        function pos(ev) {
            var r = canvas.getBoundingClientRect();
            var p = ev.touches ? ev.touches[0] : ev;
            return { x: p.clientX - r.left, y: p.clientY - r.top };
        }
        function start(ev) {
            active = true;
            var p = pos(ev);
            onDrag(toMath(p.x, p.y).x, toMath(p.x, p.y).y, 'start');
            if (ev.cancelable) ev.preventDefault();
        }
        function move(ev) {
            if (!active) return;
            var p = pos(ev);
            var m = toMath(p.x, p.y);
            onDrag(m.x, m.y, 'move');
            if (ev.cancelable) ev.preventDefault();
        }
        function end() { if (active) { active = false; onDrag(null, null, 'end'); } }
        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        window.addEventListener('touchend', end);
        canvas.style.cursor = 'grab';
        return function destroy() {
            canvas.removeEventListener('mousedown', start);
            canvas.removeEventListener('mousemove', move);
            window.removeEventListener('mouseup', end);
            canvas.removeEventListener('touchstart', start);
            canvas.removeEventListener('touchmove', move);
            window.removeEventListener('touchend', end);
        };
    }

    function slider(label, min, max, step, value, onInput) {
        var wrap = el('label', 'im-ctrl');
        wrap.appendChild(el('span', 'im-ctrl-label', label));
        var input = el('input');
        input.type = 'range';
        input.min = min; input.max = max; input.step = step; input.value = value;
        var out = el('b', 'im-ctrl-val', String(value));
        input.oninput = function () { out.textContent = input.value; onInput(parseFloat(input.value)); };
        wrap.appendChild(input);
        wrap.appendChild(out);
        return { wrap: wrap, input: input, out: out };
    }

    function button(label, cls, onClick) {
        var b = el('button', 'btn small ' + (cls || ''), label);
        b.onclick = onClick;
        return b;
    }

    // ---------------------------------------------------------------- helpers
    function fmt(x, d) {
        if (!isFinite(x)) return '∞';
        var s = x.toFixed(d == null ? 2 : d);
        return s.replace(/\.?0+$/, '') || '0';
    }

    // ---------------------------------------------------------------- models
    var KINDS = {};

    /* 1) المستوى المركّب — drag a complex number and watch its parts change. */
    KINDS['complex-plane'] = function (container, spec, opts) {
        var p = spec.params || {};
        var z = { re: p.re != null ? p.re : 3, im: p.im != null ? p.im : 4 };
        var view = p.range || 6;
        var c = setupCanvas(container, 300);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);

        function toPx(w, h, x, y) {
            return [w / 2 + (x / view) * (w / 2), h / 2 - (y / view) * (h / 2)];
        }
        function toMath(w, h, px, py) {
            return { x: ((px - w / 2) / (w / 2)) * view, y: -((py - h / 2) / (h / 2)) * view };
        }
        function snap(v) { return Math.round(v * 4) / 4; } // quarter-unit grid

        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            ctx.strokeStyle = GRID; ctx.lineWidth = 1;
            for (var g = -view; g <= view; g++) {
                var a = toPx(w, h, g, view), b = toPx(w, h, g, -view);
                ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); ctx.stroke();
                var d = toPx(w, h, view, g), e = toPx(w, h, -view, g);
                ctx.beginPath(); ctx.moveTo(d[0], d[1]); ctx.lineTo(e[0], e[1]); ctx.stroke();
            }
            ctx.strokeStyle = AXIS; ctx.lineWidth = 1.5;
            var o = toPx(w, h, 0, 0);
            ctx.beginPath(); ctx.moveTo(0, o[1]); ctx.lineTo(w, o[1]); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(o[0], 0); ctx.lineTo(o[0], h); ctx.stroke();

            // modulus ray + argument arc
            var zpt = toPx(w, h, z.re, z.im);
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.moveTo(o[0], o[1]); ctx.lineTo(zpt[0], zpt[1]); ctx.stroke();
            var mod = Math.hypot(z.re, z.im), arg = Math.atan2(z.im, z.re);
            ctx.strokeStyle = ACCENT2; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.arc(o[0], o[1], Math.min(mod * (w / 2) / view, 46), 0, -arg, arg > 0);
            ctx.stroke();

            // conjugate, mirrored across the real axis
            var cpt = toPx(w, h, z.re, -z.im);
            ctx.strokeStyle = '#94a3b8'; ctx.setLineDash([4, 4]);
            ctx.beginPath(); ctx.moveTo(zpt[0], zpt[1]); ctx.lineTo(cpt[0], cpt[1]); ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = '#94a3b8';
            ctx.beginPath(); ctx.arc(cpt[0], cpt[1], 5, 0, 7); ctx.fill();

            ctx.fillStyle = ACCENT;
            ctx.beginPath(); ctx.arc(zpt[0], zpt[1], 8, 0, 7); ctx.fill();
            ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();

            ctx.fillStyle = INK; ctx.font = '13px system-ui, sans-serif';
            ctx.fillText('ت (الجزء التخيلي)', o[0] + 6, 16);
            ctx.fillText('ح (الجزء الحقيقي)', w - 130, o[1] - 8);
            ctx.fillText('ع', zpt[0] + 12, zpt[1] - 8);
            ctx.fillStyle = '#94a3b8';
            ctx.fillText('ع̅ (المرافق)', cpt[0] + 12, cpt[1] + 16);
        }
        c.canvas._draw = draw;
        draw(c.canvas.clientWidth || container.clientWidth, 300);

        function update() {
            var mod = Math.hypot(z.re, z.im), arg = Math.atan2(z.im, z.re);
            var deg = (arg * 180 / Math.PI);
            readout.innerHTML =
                '<code>ع = ' + fmt(z.re) + (z.im < 0 ? ' − ' : ' + ') + fmt(Math.abs(z.im)) + 'ت</code>' +
                '<code>|ع| = √(' + fmt(z.re) + '² + ' + fmt(z.im) + '²) = ' + fmt(mod) + '</code>' +
                '<code>الزاوية هـ = ' + fmt(deg, 1) + '°</code>' +
                '<code>المرافق = ' + fmt(z.re) + (z.im < 0 ? ' + ' : ' − ') + fmt(Math.abs(z.im)) + 'ت</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'complex-plane', z: { re: z.re, im: z.im }, modulus: mod, argument: deg });
        }

        var killDrag = draggable(c.canvas, function (px, py) {
            return toMath(c.canvas.clientWidth, 300, px, py);
        }, function (x, y, phase) {
            if (x == null) return;
            z.re = snap(Math.max(-view, Math.min(view, x)));
            z.im = snap(Math.max(-view, Math.min(view, y)));
            draw(c.canvas.clientWidth, 300);
            update();
        });

        container.appendChild(el('p', 'im-hint', 'اسحب النقطة ع داخل المستوى لترى تغيّر الأجزاء والمعيار والزاوية.'));
        update();

        return {
            destroy: function () { killDrag(); if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                return ['العدد المركب ع = ' + fmt(z.re) + (z.im < 0 ? ' ناقص ' : ' زائد ') + fmt(Math.abs(z.im)) + ' ت.',
                    'جزؤه الحقيقي ' + fmt(z.re) + ' وجزؤه التخيلي ' + fmt(z.im) + '.',
                    'معياره يساوي ' + fmt(Math.hypot(z.re, z.im)) + '، وزاويته ' + fmt(Math.atan2(z.im, z.re) * 180 / Math.PI, 1) + ' درجة.'];
            },
        };
    };

    /* 2) منحنى دالة ومماس متحرّك — the derivative as a sliding tangent. */
    var FN_LIB = {
        'x^2': { f: function (x) { return x * x; }, df: function (x) { return 2 * x; }, label: 'د(س) = س²' },
        'x^3-3x': { f: function (x) { return x * x * x - 3 * x; }, df: function (x) { return 3 * x * x - 3; }, label: 'د(س) = س³ − ٣س' },
        'sin': { f: Math.sin, df: Math.cos, label: 'د(س) = جا س' },
        'exp': { f: Math.exp, df: Math.exp, label: 'د(س) = هـ^س' },
        'ln': { f: Math.log, df: function (x) { return 1 / x; }, label: 'د(س) = لو س' },
        'sqrt': { f: Math.sqrt, df: function (x) { return 1 / (2 * Math.sqrt(x)); }, label: 'د(س) = √س' },
        '1/x': { f: function (x) { return 1 / x; }, df: function (x) { return -1 / (x * x); }, label: 'د(س) = ١/س' },
    };

    KINDS['function-graph'] = function (container, spec, opts) {
        var p = spec.params || {};
        var key = p.fn && FN_LIB[p.fn] ? p.fn : 'x^2';
        var F = FN_LIB[key];
        var x0 = p.at != null ? p.at : 1;
        var win = p.window || { x: [-3, 3], y: [-2, 8] };
        var c = setupCanvas(container, 300);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);

        function toPx(w, h, x, y) {
            var sx = w / (win.x[1] - win.x[0]), sy = h / (win.y[1] - win.y[0]);
            return [ (x - win.x[0]) * sx, h - (y - win.y[0]) * sy ];
        }
        function toMath(w, h, px) {
            var sx = w / (win.x[1] - win.x[0]);
            return win.x[0] + px / sx;
        }
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            ctx.strokeStyle = GRID; ctx.lineWidth = 1;
            for (var gx = Math.ceil(win.x[0]); gx <= win.x[1]; gx++) {
                var a = toPx(w, h, gx, win.y[0]), b = toPx(w, h, gx, win.y[1]);
                ctx.beginPath(); ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]); ctx.stroke();
            }
            for (var gy = Math.ceil(win.y[0]); gy <= win.y[1]; gy++) {
                var d = toPx(w, h, win.x[0], gy), e = toPx(w, h, win.x[1], gy);
                ctx.beginPath(); ctx.moveTo(d[0], d[1]); ctx.lineTo(e[0], e[1]); ctx.stroke();
            }
            ctx.strokeStyle = AXIS; ctx.lineWidth = 1.5;
            var ox = toPx(w, h, 0, 0);
            ctx.beginPath(); ctx.moveTo(0, ox[1]); ctx.lineTo(w, ox[1]); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(ox[0], 0); ctx.lineTo(ox[0], h); ctx.stroke();

            // curve
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 2.5; ctx.beginPath();
            var started = false;
            for (var px = 0; px <= w; px += 1) {
                var x = toMath(w, h, px), y = F.f(x);
                if (!isFinite(y)) { started = false; continue; }
                var pt = toPx(w, h, x, y);
                if (pt[1] < -50 || pt[1] > h + 50) { started = false; continue; }
                if (!started) { ctx.moveTo(pt[0], pt[1]); started = true; } else ctx.lineTo(pt[0], pt[1]);
            }
            ctx.stroke();

            // tangent at x0
            var y0 = F.f(x0), m = F.df(x0);
            if (isFinite(y0) && isFinite(m)) {
                var xa = win.x[0], xb = win.x[1];
                var pa = toPx(w, h, xa, y0 + m * (xa - x0)), pb = toPx(w, h, xb, y0 + m * (xb - x0));
                ctx.strokeStyle = ACCENT2; ctx.lineWidth = 2; ctx.setLineDash([6, 4]);
                ctx.beginPath(); ctx.moveTo(pa[0], pa[1]); ctx.lineTo(pb[0], pb[1]); ctx.stroke();
                ctx.setLineDash([]);
                // rise/run triangle to show the slope
                var px0 = toPx(w, h, x0, y0), px1 = toPx(w, h, x0 + 1, y0 + m);
                ctx.strokeStyle = '#0ea5e9'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.moveTo(px0[0], px0[1]); ctx.lineTo(px1[0], px0[1]); ctx.lineTo(px1[0], px1[1]); ctx.stroke();
                var pt0 = toPx(w, h, x0, y0);
                ctx.fillStyle = ACCENT2;
                ctx.beginPath(); ctx.arc(pt0[0], pt0[1], 6, 0, 7); ctx.fill();
                ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();
            }
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 300);

        function update() {
            var y0 = F.f(x0), m = F.df(x0);
            readout.innerHTML =
                '<code>' + F.label + '</code>' +
                '<code>عند س = ' + fmt(x0) + ' ← د(س) = ' + fmt(y0) + '</code>' +
                '<code>ميل المماس دَ(س) = ' + fmt(m) + '</code>' +
                '<code>معادلة المماس: ص = ' + fmt(m) + ' س ' + (y0 - m * x0 < 0 ? '− ' : '+ ') + fmt(Math.abs(y0 - m * x0)) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'function-graph', x: x0, y: y0, slope: m });
        }

        var killDrag = draggable(c.canvas, function (px) { return { x: toMath(container.clientWidth, 300, px) }; },
            function (x, y, phase) {
                if (x == null) return;
                x0 = Math.max(win.x[0], Math.min(win.x[1], Math.round(x * 20) / 20));
                draw(container.clientWidth, 300); update();
            });

        var bar = el('div', 'im-controls');
        var sel = el('select', 'im-select');
        Object.keys(FN_LIB).forEach(function (k) {
            var o = el('option', null, FN_LIB[k].label); o.value = k;
            if (k === key) o.selected = true; sel.appendChild(o);
        });
        sel.onchange = function () { F = FN_LIB[sel.value]; key = sel.value; x0 = 1; draw(container.clientWidth, 300); update(); };
        bar.appendChild(sel);
        bar.appendChild(button('حرّك المماس', 'ghost', function () {
            var t = 0; var id = setInterval(function () {
                t += 0.08; x0 = Math.max(win.x[0], Math.min(win.x[1], win.x[0] + t * (win.x[1] - win.x[0]) / 4));
                draw(container.clientWidth, 300); update();
                if (t > 4) clearInterval(id);
            }, 90);
        }));
        container.appendChild(bar);
        container.appendChild(el('p', 'im-hint', 'اسحب داخل الرسم لتغيير نقطة التماس؛ الخط المتقطّع هو المماس وميله هو المشتقة.'));
        update();

        return {
            destroy: function () { killDrag(); if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                return ['هذا منحنى الدالة ' + F.label + '.',
                    'عند س = ' + fmt(x0) + ' تكون قيمة الدالة ' + fmt(F.f(x0)) + '.',
                    'ميل المماس عند هذه النقطة يساوي ' + fmt(F.df(x0)) + '، وهذا هو معنى المشتقة: معدّل التغيّر اللحظي.'];
            },
        };
    };

    /* 3) المساحة تحت المنحنى — the integral as a limit of rectangles. */
    KINDS['area-under-curve'] = function (container, spec, opts) {
        var p = spec.params || {};
        var key = p.fn && FN_LIB[p.fn] ? p.fn : 'x^2';
        var F = FN_LIB[key];
        var a = p.a != null ? p.a : 0, b = p.b != null ? p.b : 2;
        var n = p.n != null ? p.n : 4;
        var c = setupCanvas(container, 280);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        var ymax = Math.max(F.f(b), F.f(a), 1) * 1.15;
        var win = { x: [Math.min(0, a) - 0.5, b + 0.5], y: [0, ymax] };

        function toPx(w, h, x, y) {
            return [(x - win.x[0]) / (win.x[1] - win.x[0]) * w, h - (y - win.y[0]) / (win.y[1] - win.y[0]) * h];
        }
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            ctx.strokeStyle = GRID;
            for (var gx = Math.ceil(win.x[0]); gx <= win.x[1]; gx++) {
                var t = toPx(w, h, gx, win.y[0]), u = toPx(w, h, gx, win.y[1]);
                ctx.beginPath(); ctx.moveTo(t[0], t[1]); ctx.lineTo(u[0], u[1]); ctx.stroke();
            }
            ctx.strokeStyle = AXIS; ctx.lineWidth = 1.5;
            var o = toPx(w, h, 0, 0);
            ctx.beginPath(); ctx.moveTo(0, o[1]); ctx.lineTo(w, o[1]); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(o[0], 0); ctx.lineTo(o[0], h); ctx.stroke();

            var dx = (b - a) / n;
            ctx.fillStyle = 'rgba(15,118,110,.18)';
            ctx.strokeStyle = 'rgba(15,118,110,.55)'; ctx.lineWidth = 1;
            var sum = 0;
            for (var i = 0; i < n; i++) {
                var xm = a + (i + 0.5) * dx, yv = F.f(xm);
                if (!isFinite(yv)) continue;
                sum += yv * dx;
                var tl = toPx(w, h, a + i * dx, yv), br = toPx(w, h, a + (i + 1) * dx, 0);
                ctx.fillRect(tl[0], tl[1], br[0] - tl[0], br[1] - tl[1]);
                ctx.strokeRect(tl[0], tl[1], br[0] - tl[0], br[1] - tl[1]);
            }
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 2.5; ctx.beginPath();
            var started = false;
            for (var px = 0; px <= w; px += 1) {
                var x = win.x[0] + px / w * (win.x[1] - win.x[0]), y = F.f(x);
                if (!isFinite(y)) { started = false; continue; }
                var pt = toPx(w, h, x, y);
                if (!started) { ctx.moveTo(pt[0], pt[1]); started = true; } else ctx.lineTo(pt[0], pt[1]);
            }
            ctx.stroke();
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 280);

        function update() {
            var dx = (b - a) / n, sum = 0;
            for (var i = 0; i < n; i++) sum += F.f(a + (i + 0.5) * dx) * dx;
            readout.innerHTML =
                '<code>' + F.label + ' على [' + fmt(a) + ' ، ' + fmt(b) + ']</code>' +
                '<code>عدد المستطيلات ن = ' + n + ' ، عرض الواحد Δس = ' + fmt(dx, 3) + '</code>' +
                '<code>مجموع ريمان ≈ ' + fmt(sum, 4) + '</code>' +
                '<code>كلما زاد ن اقترب المجموع من التكامل ∫ د(س) دس</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'area-under-curve', n: n, sum: sum });
        }
        var bar = el('div', 'im-controls');
        bar.appendChild(slider('عدد المستطيلات ن', 1, 60, 1, n, function (v) { n = v; draw(container.clientWidth, 280); update(); }).wrap);
        container.appendChild(bar);
        container.appendChild(el('p', 'im-hint', 'زد عدد المستطيلات تدريجيًا: يقترب المجموع من المساحة الحقيقية تحت المنحنى، وهذا هو التكامل.'));
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                var dx = (b - a) / n, sum = 0;
                for (var i = 0; i < n; i++) sum += F.f(a + (i + 0.5) * dx) * dx;
                return ['نقسم المساحة تحت المنحنى إلى ' + n + ' مستطيلات.',
                    'مجموع مساحاتها الآن ' + fmt(sum, 4) + '.',
                    'كلما زدنا عدد المستطيلات صار المجموع أدقّ، وفي النهاية نحصل على قيمة التكامل.'];
            },
        };
    };

    /* 4) القطوع المخروطية — move the focus and see the curve respond. */
    KINDS['conic'] = function (container, spec, opts) {
        var p = spec.params || {};
        var kind = p.conic || 'parabola'; // parabola | ellipse | hyperbola
        var prm = { a: p.a != null ? p.a : 2, b: p.b != null ? p.b : 1.5, e: p.e != null ? p.e : 1.5 };
        var c = setupCanvas(container, 300);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        var view = 6;
        function toPx(w, h, x, y) { return [w / 2 + x / view * (w / 2), h / 2 - y / view * (h / 2)]; }
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            ctx.strokeStyle = GRID;
            for (var g = -view; g <= view; g++) {
                var a1 = toPx(w, h, g, -view), b1 = toPx(w, h, g, view);
                ctx.beginPath(); ctx.moveTo(a1[0], a1[1]); ctx.lineTo(b1[0], b1[1]); ctx.stroke();
                var d1 = toPx(w, h, -view, g), e1 = toPx(w, h, view, g);
                ctx.beginPath(); ctx.moveTo(d1[0], d1[1]); ctx.lineTo(e1[0], e1[1]); ctx.stroke();
            }
            ctx.strokeStyle = AXIS; ctx.lineWidth = 1.5;
            var o = toPx(w, h, 0, 0);
            ctx.beginPath(); ctx.moveTo(0, o[1]); ctx.lineTo(w, o[1]); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(o[0], 0); ctx.lineTo(o[0], h); ctx.stroke();

            ctx.strokeStyle = ACCENT; ctx.lineWidth = 2.5;
            var i, x, y, pt;
            if (kind === 'parabola') {
                // y² = 4 p x  (opens to +x), focus at (p, 0), directrix x = -p
                var pp = prm.a;
                ctx.beginPath();
                for (i = -view; i <= view; i += 0.05) {
                    y = i; x = (y * y) / (4 * pp);
                    pt = toPx(w, h, x, y);
                    if (i === -view) ctx.moveTo(pt[0], pt[1]); else ctx.lineTo(pt[0], pt[1]);
                }
                ctx.stroke();
                ctx.strokeStyle = ACCENT2; ctx.setLineDash([5, 5]);
                var dl = toPx(w, h, -pp, view), dr = toPx(w, h, -pp, -view);
                ctx.beginPath(); ctx.moveTo(dl[0], dl[1]); ctx.lineTo(dr[0], dr[1]); ctx.stroke();
                ctx.setLineDash([]);
                var fp = toPx(w, h, pp, 0);
                ctx.fillStyle = ACCENT2; ctx.beginPath(); ctx.arc(fp[0], fp[1], 6, 0, 7); ctx.fill();
                ctx.fillStyle = INK; ctx.font = '12px system-ui'; ctx.fillText('البؤرة', fp[0] + 8, fp[1] - 8);
            } else if (kind === 'ellipse') {
                var A = prm.a, B = prm.b, C = Math.sqrt(Math.max(A * A - B * B, 0));
                ctx.beginPath(); ctx.ellipse(o[0], o[1], A / view * (w / 2), B / view * (h / 2), 0, 0, 7); ctx.stroke();
                [-C, C].forEach(function (fx) {
                    var fp2 = toPx(w, h, fx, 0);
                    ctx.fillStyle = ACCENT2; ctx.beginPath(); ctx.arc(fp2[0], fp2[1], 5, 0, 7); ctx.fill();
                });
                ctx.fillStyle = INK; ctx.font = '12px system-ui'; ctx.fillText('البؤرتان', o[0] + 10, o[1] - 8);
            } else {
                var a2 = prm.a, b2 = prm.b;
                ctx.beginPath();
                for (i = -view; i <= view; i += 0.05) {
                    y = i; x = a2 * Math.sqrt(1 + (y * y) / (b2 * b2));
                    pt = toPx(w, h, x, y); if (i === -view) ctx.moveTo(pt[0], pt[1]); else ctx.lineTo(pt[0], pt[1]);
                }
                for (i = view; i >= -view; i -= 0.05) {
                    y = i; x = -a2 * Math.sqrt(1 + (y * y) / (b2 * b2));
                    pt = toPx(w, h, x, y); ctx.lineTo(pt[0], pt[1]);
                }
                ctx.stroke();
                ctx.strokeStyle = AXIS; ctx.setLineDash([6, 6]); ctx.lineWidth = 1.5;
                var s1 = toPx(w, h, -view, -view * b2 / a2), s2 = toPx(w, h, view, view * b2 / a2);
                ctx.beginPath(); ctx.moveTo(s1[0], s1[1]); ctx.lineTo(s2[0], s2[1]); ctx.stroke();
                var t1 = toPx(w, h, -view, view * b2 / a2), t2 = toPx(w, h, view, -view * b2 / a2);
                ctx.beginPath(); ctx.moveTo(t1[0], t1[1]); ctx.lineTo(t2[0], t2[1]); ctx.stroke();
                ctx.setLineDash([]);
            }
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 300);
        function update() {
            if (kind === 'parabola') {
                readout.innerHTML = '<code>القطع المكافئ: ص² = ٤ ب س</code><code>البعد البؤري ب = ' + fmt(prm.a) + '</code>' +
                    '<code>البؤرة (ب، ٠) = (' + fmt(prm.a) + ' ، ٠)</code><code>الدليل: س = −' + fmt(prm.a) + '</code>';
            } else if (kind === 'ellipse') {
                var C = Math.sqrt(Math.max(prm.a * prm.a - prm.b * prm.b, 0));
                readout.innerHTML = '<code>القطع الناقص: س²/' + fmt(prm.a * prm.a) + ' + ص²/' + fmt(prm.b * prm.b) + ' = ١</code>' +
                    '<code>المحور الأكبر ٢أ = ' + fmt(2 * prm.a) + ' ، الأصغر ٢ب = ' + fmt(2 * prm.b) + '</code>' +
                    '<code>البعد البؤري جـ = ' + fmt(C) + '</code>';
            } else {
                readout.innerHTML = '<code>القطع الزائد: س²/' + fmt(prm.a * prm.a) + ' − ص²/' + fmt(prm.b * prm.b) + ' = ١</code>' +
                    '<code>الخطان التقاربيان: ص = ±(' + fmt(prm.b) + '/' + fmt(prm.a) + ') س</code>';
            }
            if (opts && opts.onChange) opts.onChange({ kind: 'conic', conic: kind, params: prm });
        }
        var bar = el('div', 'im-controls');
        var sel = el('select', 'im-select');
        [['parabola', 'قطع مكافئ'], ['ellipse', 'قطع ناقص'], ['hyperbola', 'قطع زائد']].forEach(function (o) {
            var op = el('option', null, o[1]); op.value = o[0]; if (o[0] === kind) op.selected = true; sel.appendChild(op);
        });
        sel.onchange = function () { kind = sel.value; buildControls(); draw(container.clientWidth, 300); update(); };
        bar.appendChild(sel);
        container.appendChild(bar);
        function buildControls() {
            var old = container.querySelector('.im-ctrls2'); if (old) old.remove();
            var box = el('div', 'im-controls im-ctrls2');
            if (kind === 'parabola') box.appendChild(slider('البعد البؤري ب', 0.5, 4, 0.1, prm.a, function (v) { prm.a = v; draw(container.clientWidth, 300); update(); }).wrap);
            else {
                box.appendChild(slider('أ', 0.5, 5, 0.1, prm.a, function (v) { prm.a = v; draw(container.clientWidth, 300); update(); }).wrap);
                box.appendChild(slider('ب', 0.5, 5, 0.1, prm.b, function (v) { prm.b = v; draw(container.clientWidth, 300); update(); }).wrap);
            }
            container.appendChild(box);
        }
        buildControls();
        container.appendChild(el('p', 'im-hint', 'غيّر البُعد البؤري أو المحورين: لاحظ كيف يتغيّر شكل القطع وموقع البؤرة والدليل.'));
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                if (kind === 'parabola') return ['القطع المكافئ ص² = ٤ ب س.', 'بُعده البؤري ' + fmt(prm.a) + '، فبؤرته عند (' + fmt(prm.a) + ' ، ٠) ودليله س = −' + fmt(prm.a) + '.', 'كل نقطة على القطع تبعد عن البؤرة بمقدار بعدها عن الدليل.'];
                if (kind === 'ellipse') return ['القطع الناقص مجموع بُعدي أي نقطة عن البؤرتين ثابت.', 'طول محوره الأكبر ' + fmt(2 * prm.a) + ' وأصغره ' + fmt(2 * prm.b) + '.'];
                return ['القطع الزائد فرق بُعدي أي نقطة عن البؤرتين ثابت.', 'له خطان تقاربيان يقترب منهما المنحنى دون أن يلمسه.'];
            },
        };
    };

    /* 5) مثلث باسكال وذات الحدين — binomial coefficients, row by row. */
    KINDS['pascal'] = function (container, spec, opts) {
        var p = spec.params || {};
        var n = p.n != null ? p.n : 4;
        var c = setupCanvas(container, 260);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        function row(r) { var out = [1]; for (var i = 1; i <= r; i++) out.push(out[i - 1] * (r - i + 1) / i); return out; }
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            var maxR = Math.max(n, 5), cw = w / (maxR + 1), ch = Math.min(h / (maxR + 1), 34);
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            for (var r = 0; r <= maxR; r++) {
                var vals = row(r), startX = w / 2 - (vals.length - 1) * cw / 2;
                for (var k = 0; k < vals.length; k++) {
                    var x = startX + k * cw, y = 20 + r * ch;
                    var isTarget = r === n;
                    ctx.fillStyle = isTarget ? 'rgba(15,118,110,.85)' : 'rgba(148,163,184,.16)';
                    ctx.beginPath(); ctx.arc(x, y, Math.min(cw, ch) / 2 - 2, 0, 7); ctx.fill();
                    ctx.fillStyle = isTarget ? '#fff' : INK;
                    ctx.font = (isTarget ? 'bold ' : '') + '12px system-ui';
                    ctx.fillText(String(Math.round(vals[k])), x, y);
                }
            }
            ctx.textAlign = 'start';
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 260);
        function expand() {
            var vals = row(n), terms = [];
            for (var k = 0; k <= n; k++) {
                var cf = Math.round(vals[k]);
                var ap = (n - k) > 0 ? 'أ' + (n - k > 1 ? '^' + (n - k) : '') : '';
                var bp = k > 0 ? 'ب' + (k > 1 ? '^' + k : '') : '';
                terms.push((cf === 1 && (ap || bp) ? '' : cf) + ap + bp);
            }
            return terms.join(' + ');
        }
        function update() {
            var vals = row(n);
            readout.innerHTML = '<code>(أ + ب)^' + n + ' = ' + expand() + '</code>' +
                '<code>المعاملات: ' + vals.map(function (v) { return Math.round(v); }).join(' ، ') + '</code>' +
                '<code>عدد الحدود = ' + (n + 1) + ' ، ومجموع المعاملات = ٢^' + n + ' = ' + Math.pow(2, n) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'pascal', n: n, coefficients: vals });
        }
        var bar = el('div', 'im-controls');
        bar.appendChild(slider('الأس ن', 0, 8, 1, n, function (v) { n = v; draw(container.clientWidth, 260); update(); }).wrap);
        container.appendChild(bar);
        container.appendChild(el('p', 'im-hint', 'كل معامل يساوي مجموع المعاملين فوقه مباشرة — وهذا هو أساس مفكوك ذات الحدين.'));
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                var vals = row(n);
                return ['معاملات مفكوك (أ + ب) أس ' + n + ' هي ' + vals.map(function (v) { return Math.round(v); }).join('، ') + '.',
                    'نقرأها من الصف رقم ' + n + ' في مثلث باسكال.',
                    'فيكون المفكوك: ' + expand() + '.'];
            },
        };
    };

    /* 6) شجرة الاحتمالات — a probability tree that multiplies along the path. */
    KINDS['probability-tree'] = function (container, spec, opts) {
        var p = spec.params || {};
        var p1 = p.p1 != null ? p.p1 : 0.6;
        var p2 = p.p2 != null ? p.p2 : 0.5;
        var c = setupCanvas(container, 260);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            var x0 = 26, x1 = w / 2, x2 = w - 30;
            var yTop = 60, yBot = h - 60;
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.moveTo(x0, h / 2); ctx.lineTo(x1, yTop); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x0, h / 2); ctx.lineTo(x1, yBot); ctx.stroke();
            var t1 = yTop - 30, b1 = yBot + 30;
            ctx.beginPath(); ctx.moveTo(x1, yTop); ctx.lineTo(x2, t1); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x1, yTop); ctx.lineTo(x2, yTop + 30); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x1, yBot); ctx.lineTo(x2, yBot - 30); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x1, yBot); ctx.lineTo(x2, b1); ctx.stroke();
            function node(x, y, label) {
                ctx.fillStyle = '#fff'; ctx.strokeStyle = ACCENT; ctx.lineWidth = 2;
                ctx.beginPath(); ctx.arc(x, y, 7, 0, 7); ctx.fill(); ctx.stroke();
                if (label) { ctx.fillStyle = INK; ctx.font = '12px system-ui'; ctx.fillText(label, x + 10, y + 4); }
            }
            node(x0, h / 2, 'البداية');
            node(x1, yTop, 'ح₁'); node(x1, yBot, 'ح̄₁');
            ctx.fillStyle = ACCENT2; ctx.font = '12px system-ui';
            ctx.fillText('p = ' + fmt(p1), x0 + 12, (h / 2 + yTop) / 2);
            ctx.fillText('p = ' + fmt(1 - p1), x0 + 12, (h / 2 + yBot) / 2);
            ctx.fillText('p = ' + fmt(p2), x1 + 8, (yTop + t1) / 2);
            ctx.fillText('p = ' + fmt(1 - p2), x1 + 8, (yTop + yTop + 30) / 2);
            ctx.fillText('p = ' + fmt(p2), x1 + 8, (yBot + yBot - 30) / 2);
            ctx.fillText('p = ' + fmt(1 - p2), x1 + 8, (yBot + b1) / 2);
            ctx.fillStyle = INK; ctx.font = 'bold 12px system-ui';
            ctx.fillText(fmt(p1 * p2, 3), x2 - 34, t1 + 12);
            ctx.fillText(fmt(p1 * (1 - p2), 3), x2 - 34, yTop + 44);
            ctx.fillText(fmt((1 - p1) * p2, 3), x2 - 34, yBot - 18);
            ctx.fillText(fmt((1 - p1) * (1 - p2), 3), x2 - 34, b1 + 12);
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 260);
        function update() {
            readout.innerHTML = '<code>ح₁ ثم ح₂ = ' + fmt(p1 * p2, 3) + '</code>' +
                '<code>ح₁ ثم ح̄₂ = ' + fmt(p1 * (1 - p2), 3) + '</code>' +
                '<code>ح̄₁ ثم ح₂ = ' + fmt((1 - p1) * p2, 3) + '</code>' +
                '<code>ح̄₁ ثم ح̄₂ = ' + fmt((1 - p1) * (1 - p2), 3) + '</code>' +
                '<code>المجموع = ١ (تحقّق دائم)</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'probability-tree', p1: p1, p2: p2 });
        }
        var bar = el('div', 'im-controls');
        bar.appendChild(slider('احتمال ح₁', 0, 1, 0.05, p1, function (v) { p1 = v; draw(container.clientWidth, 260); update(); }).wrap);
        bar.appendChild(slider('احتمال ح₂', 0, 1, 0.05, p2, function (v) { p2 = v; draw(container.clientWidth, 260); update(); }).wrap);
        container.appendChild(bar);
        container.appendChild(el('p', 'im-hint', 'غيّر الاحتمالات: احتمال المسار يساوي حاصل ضرب احتمالات فروعه (قانون الضرب).'));
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                return ['احتمال الحدث الأول ' + fmt(p1) + ' والثاني ' + fmt(p2) + '.',
                    'احتمال المسار الأول يساوي حاصل ضربهما: ' + fmt(p1 * p2, 3) + '.',
                    'ومجموع احتمالات المسارات الأربعة يساوي واحدًا دائمًا.'];
            },
        };
    };

    /* 7) الدائرة الوحدة — sine and cosine as coordinates on the circle. */
    KINDS['unit-circle'] = function (container, spec, opts) {
        var p = spec.params || {};
        var deg = p.deg != null ? p.deg : 30;
        var c = setupCanvas(container, 280);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            var cx = w / 2, cy = h / 2, R = Math.min(w, h) / 2 - 28;
            ctx.strokeStyle = GRID;
            ctx.beginPath(); ctx.moveTo(cx - R - 12, cy); ctx.lineTo(cx + R + 12, cy); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(cx, cy - R - 12); ctx.lineTo(cx, cy + R + 12); ctx.stroke();
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.arc(cx, cy, R, 0, 7); ctx.stroke();
            var rad = deg * Math.PI / 180;
            var x = Math.cos(rad), y = Math.sin(rad);
            var px = cx + x * R, py = cy - y * R;
            ctx.strokeStyle = ACCENT2; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(px, py); ctx.stroke();
            ctx.strokeStyle = '#0ea5e9'; ctx.lineWidth = 2; ctx.setLineDash([4, 4]);
            ctx.beginPath(); ctx.moveTo(px, py); ctx.lineTo(px, cy); ctx.stroke();
            ctx.strokeStyle = '#7c3aed';
            ctx.beginPath(); ctx.moveTo(px, py); ctx.lineTo(cx, py); ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = ACCENT2; ctx.beginPath(); ctx.arc(px, py, 6, 0, 7); ctx.fill();
            ctx.fillStyle = INK; ctx.font = '12px system-ui';
            ctx.fillText('جا هـ', cx + 6, py + 14);
            ctx.fillText('جتا هـ', (cx + px) / 2 - 14, cy - 8);
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 280);
        function update() {
            var rad = deg * Math.PI / 180;
            readout.innerHTML = '<code>الزاوية هـ = ' + deg + '°</code>' +
                '<code>جتا ' + deg + '° = ' + fmt(Math.cos(rad), 3) + '</code>' +
                '<code>جا ' + deg + '° = ' + fmt(Math.sin(rad), 3) + '</code>' +
                '<code>ظا = ' + fmt(Math.tan(rad), 3) + ' ، جا² + جتا² = ' + fmt(Math.sin(rad) ** 2 + Math.cos(rad) ** 2, 3) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'unit-circle', deg: deg });
        }
        var bar = el('div', 'im-controls');
        bar.appendChild(slider('الزاوية هـ (درجة)', 0, 360, 5, deg, function (v) { deg = v; draw(container.clientWidth, 280); update(); }).wrap);
        container.appendChild(bar);
        container.appendChild(el('p', 'im-hint', 'حرّك الزاوية: جتا هي الإحداثي الأفقي، وجا هي الإحداثي الرأسي لنقطة على الدائرة الوحدة.'));
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                var rad = deg * Math.PI / 180;
                return ['على الدائرة الوحدة، الزاوية ' + deg + ' درجة.',
                    'جتا ' + deg + ' تساوي ' + fmt(Math.cos(rad), 3) + ' وهي الإحداثي الأفقي.',
                    'جا ' + deg + ' تساوي ' + fmt(Math.sin(rad), 3) + ' وهي الإحداثي الرأسي، ومجموع مربعيهما يساوي واحدًا.'];
            },
        };
    };

    /* 8) المتجهات في الفضاء — a 3-D vector drawn with a simple projection. */
    KINDS['vector-space'] = function (container, spec, opts) {
        var p = spec.params || {};
        var v = { x: p.x != null ? p.x : 2, y: p.y != null ? p.y : 3, z: p.z != null ? p.z : 1.5 };
        var c = setupCanvas(container, 300);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        var scale = 34;
        function proj(x, y, z) {
            // isometric-ish projection: x right, z up, y depth to the right-back
            var sx = (x - y * 0.55) * scale;
            var sy = -(z - y * 0.32) * scale;
            return [sx, sy];
        }
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            var ox = w / 2 - 30, oy = h - 60;
            function P(x, y, z) { var q = proj(x, y, z); return [ox + q[0], oy + q[1]]; }
            var O = P(0, 0, 0);
            var axes = [[P(4, 0, 0), 'س'], [P(0, 4, 0), 'ص'], [P(0, 0, 4), 'ع']];
            ctx.strokeStyle = AXIS; ctx.lineWidth = 1.5;
            axes.forEach(function (a) {
                ctx.beginPath(); ctx.moveTo(O[0], O[1]); ctx.lineTo(a[0][0], a[0][1]); ctx.stroke();
                ctx.fillStyle = INK; ctx.font = '13px system-ui'; ctx.fillText(a[1], a[0][0] + 4, a[0][1]);
            });
            var V = P(v.x, v.y, v.z);
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 3;
            ctx.beginPath(); ctx.moveTo(O[0], O[1]); ctx.lineTo(V[0], V[1]); ctx.stroke();
            // dashed components
            ctx.setLineDash([4, 4]); ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 1.5;
            [[v.x, 0, 0], [v.x, v.y, 0], [v.x, v.y, v.z]].forEach(function (pt) {
                var q = P(pt[0], pt[1], pt[2]); var q2 = P(pt[0], pt[1], 0);
                ctx.beginPath(); ctx.moveTo(q2[0], q2[1]); ctx.lineTo(q[0], q[1]); ctx.stroke();
            });
            ctx.setLineDash([]);
            ctx.fillStyle = ACCENT; ctx.beginPath(); ctx.arc(V[0], V[1], 7, 0, 7); ctx.fill();
            ctx.fillStyle = INK; ctx.font = 'bold 13px system-ui';
            ctx.fillText('ع⃗', V[0] + 10, V[1] - 6);
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 300);
        function update() {
            var mag = Math.sqrt(v.x * v.x + v.y * v.y + v.z * v.z);
            readout.innerHTML = '<code>ع⃗ = (' + fmt(v.x) + ' ، ' + fmt(v.y) + ' ، ' + fmt(v.z) + ')</code>' +
                '<code>|ع⃗| = √(' + fmt(v.x) + '² + ' + fmt(v.y) + '² + ' + fmt(v.z) + '²) = ' + fmt(mag) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'vector-space', v: v, magnitude: mag });
        }
        var bar = el('div', 'im-controls');
        ['x', 'y', 'z'].forEach(function (k) {
            bar.appendChild(slider('المركّبة ' + ({ x: 'س', y: 'ص', z: 'ع' })[k], -3, 5, 0.5, v[k], function (val) {
                v[k] = val; draw(container.clientWidth, 300); update();
            }).wrap);
        });
        container.appendChild(bar);
        container.appendChild(el('p', 'im-hint', 'غيّر مركّبات المتجه: طوله يساوي الجذر التربيعي لمجموع مربّعات مركّباته.'));
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                var mag = Math.sqrt(v.x * v.x + v.y * v.y + v.z * v.z);
                return ['المتجه في الفضاء له ثلاث مركّبات: ' + fmt(v.x) + ' و' + fmt(v.y) + ' و' + fmt(v.z) + '.',
                    'طوله يساوي الجذر التربيعي لمجموع مربّعات المركّبات، أي ' + fmt(mag) + '.'];
            },
        };
    };

    /* 9) مستقيم الأعداد — intervals and inequalities made visible. */
    KINDS['number-line'] = function (container, spec, opts) {
        var p = spec.params || {};
        var a = p.a != null ? p.a : -2, b = p.b != null ? p.b : 3;
        var lo = p.lo != null ? p.lo : -6, hi = p.hi != null ? p.hi : 6;
        var c = setupCanvas(container, 150);
        var readout = el('div', 'im-readout');
        container.appendChild(readout);
        function draw(w, h) {
            var ctx = c.ctx;
            ctx.clearRect(0, 0, w, h);
            var pad = 30, y = h / 2;
            function X(v) { return pad + (v - lo) / (hi - lo) * (w - 2 * pad); }
            ctx.strokeStyle = AXIS; ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.moveTo(pad - 10, y); ctx.lineTo(w - pad + 10, y); ctx.stroke();
            ctx.fillStyle = INK; ctx.font = '11px system-ui'; ctx.textAlign = 'center';
            for (var t = Math.ceil(lo); t <= hi; t++) {
                ctx.beginPath(); ctx.moveTo(X(t), y - 5); ctx.lineTo(X(t), y + 5); ctx.stroke();
                ctx.fillText(String(t), X(t), y + 20);
            }
            ctx.textAlign = 'start';
            var x1 = X(Math.min(a, b)), x2 = X(Math.max(a, b));
            ctx.strokeStyle = ACCENT; ctx.lineWidth = 4;
            ctx.beginPath(); ctx.moveTo(x1, y); ctx.lineTo(x2, y); ctx.stroke();
            [a, b].forEach(function (v) {
                ctx.fillStyle = '#fff'; ctx.strokeStyle = ACCENT; ctx.lineWidth = 3;
                ctx.beginPath(); ctx.arc(X(v), y, 7, 0, 7); ctx.fill(); ctx.stroke();
                ctx.fillStyle = ACCENT; ctx.font = 'bold 12px system-ui';
                ctx.fillText(fmt(v), X(v) - 4, y - 14);
            });
        }
        c.canvas._draw = draw;
        draw(container.clientWidth, 150);
        function update() {
            readout.innerHTML = '<code>الفترة: [' + fmt(Math.min(a, b)) + ' ، ' + fmt(Math.max(a, b)) + ']</code>' +
                '<code>الطول = ' + fmt(Math.abs(b - a)) + '</code>' +
                '<code>تمثيل المتباينة: ' + fmt(Math.min(a, b)) + ' ≤ س ≤ ' + fmt(Math.max(a, b)) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'number-line', a: a, b: b });
        }
        var bar = el('div', 'im-controls');
        bar.appendChild(slider('الطرف الأول', lo, hi, 0.5, a, function (v) { a = v; draw(container.clientWidth, 150); update(); }).wrap);
        bar.appendChild(slider('الطرف الثاني', lo, hi, 0.5, b, function (v) { b = v; draw(container.clientWidth, 150); update(); }).wrap);
        container.appendChild(bar);
        update();
        return {
            destroy: function () { if (c.ro) c.ro.disconnect(); container.innerHTML = ''; },
            narrate: function () {
                return ['نُمثّل الفترة على مستقيم الأعداد من ' + fmt(Math.min(a, b)) + ' إلى ' + fmt(Math.max(a, b)) + '.',
                    'طول هذه الفترة يساوي ' + fmt(Math.abs(b - a)) + '.'];
            },
        };
    };

    // ---------------------------------------------------------------- public API
    var Interactive = {
        kinds: Object.keys(KINDS),
        supports: function (kind) { return !!KINDS[kind]; },
        render: function (container, spec, opts) {
            container.innerHTML = '';
            container.classList.add('im-model');
            if (!spec || !spec.kind) { container.classList.remove('im-model'); return null; }
            var factory = KINDS[spec.kind];
            if (!factory) {
                container.classList.remove('im-model');
                container.appendChild(el('p', 'muted', 'نموذج غير معروف: ' + spec.kind));
                return null;
            }
            var model = factory(container, spec, opts || {});
            // Every model gets the same "اشرح لي" affordance: the teacher reads
            // the current state of the figure, so the narration always matches
            // what the learner is looking at right now.
            if (window.speak) {
                var bar = el('div', 'im-narrate');
                bar.appendChild(button('👩‍🏫 اشرحي لي هذا الشكل', 'primary', function () {
                    window.speak(model.narrate().join(' '));
                }));
                container.appendChild(bar);
            }
            if (spec.prompt) container.insertBefore(el('p', 'im-prompt', spec.prompt), container.firstChild);
            return model;
        },
    };

    window.Interactive = Interactive;
})();
