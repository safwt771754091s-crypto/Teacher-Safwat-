/* محرّك النماذج العلمية والرياضية — Science & maths models.
 *
 * The maths book and the authored lessons already have their own engines
 * (interactive.js, interactive3d.js, interactive-lessons.js). This file covers
 * the rest of the curriculum: the science subjects and the maths grades other
 * than Grade 12, whose lessons have real topic titles (المثلثات، نظرية
 * فيثاغورس، الذرة، الكهرباء، الخلية …) but no authored prose yet.
 *
 * Only the topic is used, never invented book text: a lesson called «الذرة»
 * gets an atom diagram because that is what an atom lesson is about, and the
 * model's own labels are the standard, factual names for the parts. Nothing
 * here claims to be quoted from the Yemeni edition.
 *
 * Rendered as inline SVG (diagrams, circuits, waves) or canvas (graphs,
 * geometry) — SVG for anything with Arabic labels so the browser shapes the
 * text correctly, canvas only for pure figures.
 */
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';

    function svg(w, h, cls) {
        var s = document.createElementNS(NS, 'svg');
        s.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
        s.setAttribute('width', '100%');
        s.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        if (cls) s.setAttribute('class', cls);
        s.style.maxHeight = h + 'px';
        return s;
    }

    function add(parent, tag, attrs, text) {
        var n = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (text != null) n.textContent = text;
        parent.appendChild(n);
        return n;
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function readout() { return el('div', 'im-readout'); }

    function say(t) { if (window.speak) window.speak(t); }

    function fmt(x, d) {
        if (!isFinite(x)) return '∞';
        var s = x.toFixed(d == null ? 2 : d);
        return s.replace(/\.?0+$/, '') || '0';
    }

    var GREEN = '#2f6a43', AMBER = '#b45309', BLUE = '#1d4ed8', RED = '#b91c1c';

    var KINDS = {};

    /* ---------------------------------------------------------------- diagram
     * A labelled diagram with tappable hotspots. One kind covers a whole family
     * of biology/physics/chemistry topics: the atom, the cell, the plant, the
     * digestive system, the eye, the water cycle …
     */
    KINDS['diagram'] = function (container, spec, opts) {
        var p = spec.params || {};
        var w = 340, h = 260;
        var s = svg(w, h, 'im-diagram');
        var defs = add(s, 'defs');
        var grad = add(defs, 'radialGradient', { id: 'dg', cx: '50%', cy: '45%', r: '60%' });
        add(grad, 'stop', { offset: '0%', 'stop-color': '#ecfdf5' });
        add(grad, 'stop', { offset: '100%', 'stop-color': '#d1fae5' });

        // A neutral body: a rounded plate the caller can decorate with parts.
        add(s, 'rect', { x: 20, y: 20, width: w - 40, height: h - 40, rx: 18, fill: 'url(#dg)', stroke: GREEN, 'stroke-width': 2 });

        var parts = p.parts || [];
        var labels = add(s, 'g', {});
        var read = readout();
        var active = null;

        parts.forEach(function (part, i) {
            var cx = part.x != null ? part.x : w / 2;
            var cy = part.y != null ? part.y : h / 2;
            var r = part.r || 16;
            var dot = add(s, 'circle', {
                cx: cx, cy: cy, r: r, fill: part.color || '#a7f3d0',
                stroke: GREEN, 'stroke-width': 2, style: 'cursor:pointer',
            });
            var t = add(s, 'text', {
                x: cx, y: cy + 4, 'text-anchor': 'middle', 'font-size': 12,
                'font-family': 'system-ui', fill: '#1f2937', style: 'pointer-events:none',
            }, String(i + 1));

            function pick() {
                active = part;
                labels.innerHTML = '';
                add(labels, 'line', { x1: cx, y1: cy, x2: part.lx || w - 34, y2: part.ly || 34, stroke: AMBER, 'stroke-width': 1.5, 'stroke-dasharray': '4 3' });
                add(labels, 'text', { x: part.lx || w - 34, y: (part.ly || 34) - 6, 'text-anchor': 'end', 'font-size': 13, 'font-family': 'system-ui', 'font-weight': 'bold', fill: GREEN }, part.label);
                read.innerHTML = '<code>' + part.label + '</code>' + (part.note ? '<code>' + part.note + '</code>' : '');
                say(part.label + (part.note ? ' ، ' + part.note : ''));
                if (opts && opts.onChange) opts.onChange({ kind: 'diagram', part: part.label });
            }
            dot.addEventListener('click', pick);
            dot.addEventListener('touchstart', function (e) { pick(); if (e.cancelable) e.preventDefault(); }, { passive: false });
        });

        s.appendChild(labels);
        container.appendChild(s);
        var bar = el('div', 'im-controls');
        bar.appendChild(el('span', 'muted small', 'اضغط على الأرقام لتسمع اسم كل جزء.'));
        container.appendChild(bar);
        container.appendChild(read);
        read.innerHTML = '<code>اضغط على أي جزء في الشكل.</code>';

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () {
                return ['أجزاء ' + (p.title || 'الشكل') + ':'].concat(parts.map(function (x, i) { return (i + 1) + ') ' + x.label + (x.note ? ': ' + x.note : ''); }));
            },
        };
    };

    /* ----------------------------------------------------------------- shapes
     * A 2-D shape with live perimeter and area. Covers الأشكال الهندسية،
     * المحيط والمساحة، المربع والمستطيل والمثلث والدائرة.
     */
    KINDS['shapes'] = function (container, spec, opts) {
        var p = spec.params || {};
        var shape = p.shape || 'rectangle';
        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '240px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);

        var a = p.a != null ? p.a : 4, b = p.b != null ? p.b : 3, r = p.r != null ? p.r : 2;
        var read = readout();

        function area() {
            switch (shape) {
                case 'square': return a * a;
                case 'triangle': return (a * b) / 2;
                case 'circle': return Math.PI * r * r;
                default: return a * b;
            }
        }
        function perim() {
            switch (shape) {
                case 'square': return 4 * a;
                case 'triangle': return a + b + Math.sqrt(a * a + b * b);
                case 'circle': return 2 * Math.PI * r;
                default: return 2 * (a + b);
            }
        }

        function draw() {
            var w = container.clientWidth || 320, h = 240;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            ctx.strokeStyle = GREEN; ctx.fillStyle = 'rgba(47,106,67,.12)'; ctx.lineWidth = 3;
            var cx = w / 2, cy = h / 2, k = 26;   // pixels per unit

            ctx.beginPath();
            if (shape === 'circle') {
                ctx.arc(cx, cy, r * k, 0, Math.PI * 2);
                ctx.moveTo(cx, cy); ctx.lineTo(cx + r * k, cy);
            } else if (shape === 'triangle') {
                ctx.moveTo(cx - (a * k) / 2, cy + (b * k) / 2);
                ctx.lineTo(cx - (a * k) / 2, cy - (b * k) / 2);
                ctx.lineTo(cx + (a * k) / 2, cy + (b * k) / 2);
                ctx.closePath();
            } else {
                var ww = (shape === 'square' ? a : a) * k, hh = (shape === 'square' ? a : b) * k;
                ctx.rect(cx - ww / 2, cy - hh / 2, ww, hh);
            }
            ctx.fill(); ctx.stroke();

            ctx.fillStyle = '#1f2937'; ctx.font = '13px system-ui'; ctx.textAlign = 'center';
            ctx.fillText('المحيط = ' + fmt(perim()), cx, h - 22);
            ctx.fillText('المساحة = ' + fmt(area()), cx, h - 5);
            if (shape === 'circle') { ctx.textAlign = 'left'; ctx.fillStyle = AMBER; ctx.fillText('نق = ' + fmt(r), cx + 6, cy - 6); }

            read.innerHTML = '<code>المحيط: ' + fmt(perim()) + '</code><code>المساحة: ' + fmt(area()) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'shapes', shape: shape, area: area(), perimeter: perim() });
        }

        var bar = el('div', 'im-controls');
        var pick = el('select', 'im-select');
        [['rectangle', 'مستطيل'], ['square', 'مربع'], ['triangle', 'مثلث قائم'], ['circle', 'دائرة']].forEach(function (o) {
            var op = el('option', null, o[1]); op.value = o[0]; if (o[0] === shape) op.selected = true; pick.appendChild(op);
        });
        pick.onchange = function () { shape = pick.value; sync(); draw(); };
        bar.appendChild(pick);

        function slider(label, val, min, max, step, on) {
            var l = el('label', 'im-ctrl');
            l.appendChild(el('span', 'im-ctrl-label', label));
            var s = el('input'); s.type = 'range'; s.min = min; s.max = max; s.step = step; s.value = val;
            s.oninput = function () { on(parseFloat(s.value)); draw(); };
            l.appendChild(s); return l;
        }
        var ctrls = el('div', 'im-controls');
        function sync() {
            ctrls.innerHTML = '';
            if (shape === 'circle') {
                ctrls.appendChild(slider('نصف القطر', r, 0.5, 5, 0.5, function (v) { r = v; }));
            } else if (shape === 'triangle') {
                ctrls.appendChild(slider('القاعدة', a, 1, 8, 1, function (v) { a = v; }));
                ctrls.appendChild(slider('الارتفاع', b, 1, 8, 1, function (v) { b = v; }));
            } else {
                ctrls.appendChild(slider('الطول', a, 1, 8, 1, function (v) { a = v; }));
                ctrls.appendChild(slider('العرض', b, 1, 8, 1, function (v) { b = v; }));
            }
        }
        sync();
        container.appendChild(bar); container.appendChild(ctrls); container.appendChild(read);
        draw();
        var ro = window.ResizeObserver ? new ResizeObserver(draw) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', draw);

        return {
            destroy: function () { if (ro) ro.disconnect(); else window.removeEventListener('resize', draw); container.innerHTML = ''; },
            narrate: function () { return ['المحيط يساوي ' + fmt(perim()) + ' وحدات.', 'والمساحة تساوي ' + fmt(area()) + ' وحدة مربّعة.']; },
        };
    };

    /* --------------------------------------------------------------- triangle
     * A right triangle with Pythagoras and the trigonometric ratios.
     */
    KINDS['triangle'] = function (container, spec, opts) {
        var p = spec.params || {};
        var a = p.a != null ? p.a : 3;   // المقابل
        var b = p.b != null ? p.b : 4;   // المجاور

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '250px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);
        var read = readout();

        function draw() {
            var w = container.clientWidth || 320, h = 250;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            var k = Math.min((w - 90) / Math.max(a, 1), (h - 80) / Math.max(b, 1));
            var ox = 50, oy = h - 40;
            var hyp = Math.sqrt(a * a + b * b);

            ctx.strokeStyle = GREEN; ctx.lineWidth = 3; ctx.fillStyle = 'rgba(47,106,67,.12)';
            ctx.beginPath();
            ctx.moveTo(ox, oy); ctx.lineTo(ox + b * k, oy); ctx.lineTo(ox + b * k, oy - a * k); ctx.closePath();
            ctx.fill(); ctx.stroke();
            // right-angle mark
            ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 2;
            ctx.strokeRect(ox + b * k - 12, oy - 12, 12, 12);

            ctx.fillStyle = '#1f2937'; ctx.font = 'bold 13px system-ui';
            ctx.textAlign = 'center';
            ctx.fillText('المجاور = ' + fmt(b), ox + (b * k) / 2, oy + 18);
            ctx.save();
            ctx.translate(ox + b * k + 16, oy - (a * k) / 2);
            ctx.fillText('المقابل = ' + fmt(a), 0, 0);
            ctx.restore();
            ctx.fillStyle = AMBER;
            ctx.fillText('الوتر = ' + fmt(hyp), ox + (b * k) / 2 - 10, oy - (a * k) / 2 - 10);

            read.innerHTML =
                '<code>الوتر = √(المقابل² + المجاور²) = ' + fmt(hyp) + '</code>' +
                '<code>جا = المقابل/الوتر = ' + fmt(a / hyp, 3) + '</code>' +
                '<code>جتا = المجاور/الوتر = ' + fmt(b / hyp, 3) + '</code>' +
                '<code>ظا = المقابل/المجاور = ' + fmt(a / b, 3) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'triangle', hypotenuse: hyp });
        }

        function slider(label, val, on) {
            var l = el('label', 'im-ctrl');
            l.appendChild(el('span', 'im-ctrl-label', label));
            var s = el('input'); s.type = 'range'; s.min = 1; s.max = 8; s.step = 1; s.value = val;
            s.oninput = function () { on(parseFloat(s.value)); draw(); };
            l.appendChild(s); return l;
        }
        var ctrls = el('div', 'im-controls');
        ctrls.appendChild(slider('المقابل', a, function (v) { a = v; }));
        ctrls.appendChild(slider('المجاور', b, function (v) { b = v; }));
        container.appendChild(ctrls);
        container.appendChild(read);
        draw();
        var ro = window.ResizeObserver ? new ResizeObserver(draw) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', draw);

        return {
            destroy: function () { if (ro) ro.disconnect(); else window.removeEventListener('resize', draw); container.innerHTML = ''; },
            narrate: function () {
                var hyp = Math.sqrt(a * a + b * b);
                return ['في المثلث القائم: المقابل ' + fmt(a) + ' والمجاور ' + fmt(b) + '.',
                    'الوتر يساوي ' + fmt(hyp) + '، لأن مربّع الوتر يساوي مجموع مربّعي الضلعين.'];
            },
        };
    };

    /* ------------------------------------------------------------ vector-2d
     * Vector addition on the plane.
     */
    KINDS['vector-2d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var u = p.u || [2, 1];
        var v = p.v || [1, 2];

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '250px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);
        var read = readout();

        function arrow(x1, y1, x2, y2, color, label) {
            ctx.strokeStyle = color; ctx.fillStyle = color; ctx.lineWidth = 2.5;
            ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
            var ang = Math.atan2(y2 - y1, x2 - x1);
            ctx.beginPath();
            ctx.moveTo(x2, y2);
            ctx.lineTo(x2 - 9 * Math.cos(ang - 0.4), y2 - 9 * Math.sin(ang - 0.4));
            ctx.lineTo(x2 - 9 * Math.cos(ang + 0.4), y2 - 9 * Math.sin(ang + 0.4));
            ctx.closePath(); ctx.fill();
            if (label) { ctx.font = 'bold 12px system-ui'; ctx.fillText(label, x2 + 6, y2 - 6); }
        }

        function draw() {
            var w = container.clientWidth || 320, h = 250;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            var ox = w / 2, oy = h / 2, k = 34;

            ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 1;
            for (var g = -4; g <= 4; g++) {
                ctx.beginPath(); ctx.moveTo(ox + g * k, 10); ctx.lineTo(ox + g * k, h - 10); ctx.stroke();
                ctx.beginPath(); ctx.moveTo(10, oy + g * k); ctx.lineTo(w - 10, oy + g * k); ctx.stroke();
            }
            ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.moveTo(10, oy); ctx.lineTo(w - 10, oy); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(ox, 10); ctx.lineTo(ox, h - 10); ctx.stroke();

            arrow(ox, oy, ox + u[0] * k, oy - u[1] * k, GREEN, 'ع');
            arrow(ox, oy, ox + v[0] * k, oy - v[1] * k, BLUE, 'ك');
            var sx = u[0] + v[0], sy = u[1] + v[1];
            arrow(ox, oy, ox + sx * k, oy - sy * k, AMBER, 'ع+ك');
            ctx.setLineDash([4, 4]); ctx.strokeStyle = '#cbd5e1';
            ctx.beginPath(); ctx.moveTo(ox + u[0] * k, oy - u[1] * k); ctx.lineTo(ox + sx * k, oy - sy * k); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(ox + v[0] * k, oy - v[1] * k); ctx.lineTo(ox + sx * k, oy - sy * k); ctx.stroke();
            ctx.setLineDash([]);

            read.innerHTML = '<code>ع = (' + fmt(u[0]) + ' ، ' + fmt(u[1]) + ')</code>' +
                '<code>ك = (' + fmt(v[0]) + ' ، ' + fmt(v[1]) + ')</code>' +
                '<code>ع + ك = (' + fmt(sx) + ' ، ' + fmt(sy) + ')</code>' +
                '<code>|ع + ك| = ' + fmt(Math.sqrt(sx * sx + sy * sy), 3) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'vector-2d', sum: [sx, sy] });
        }

        function pair(label, vec, on) {
            var wrap = el('div', 'im-ctrl');
            wrap.appendChild(el('span', 'im-ctrl-label', label));
            ['x', 'y'].forEach(function (ax, i) {
                var s = el('input'); s.type = 'range'; s.min = -4; s.max = 4; s.step = 1; s.value = vec[i];
                s.oninput = function () { vec[i] = parseFloat(s.value); on(vec); draw(); };
                wrap.appendChild(s);
            });
            return wrap;
        }
        var ctrls = el('div', 'im-controls');
        ctrls.appendChild(pair('ع', u, function (n) { u = n; }));
        ctrls.appendChild(pair('ك', v, function (n) { v = n; }));
        container.appendChild(ctrls); container.appendChild(read);
        draw();
        var ro = window.ResizeObserver ? new ResizeObserver(draw) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', draw);

        return {
            destroy: function () { if (ro) ro.disconnect(); else window.removeEventListener('resize', draw); container.innerHTML = ''; },
            narrate: function () {
                return ['المتجه ع = (' + u[0] + ' ، ' + u[1] + ') والمتجه ك = (' + v[0] + ' ، ' + v[1] + ').',
                    'نجمعهما مركّبة بمركّبة: ع + ك = (' + (u[0] + v[0]) + ' ، ' + (u[1] + v[1]) + ').'];
            },
        };
    };

    /* ------------------------------------------------------- states-of-matter
     * The particle model: solid, liquid, gas, with a temperature control.
     */
    KINDS['states-of-matter'] = function (container, spec, opts) {
        var p = spec.params || {};
        var t = p.t != null ? p.t : 20;

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '230px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);
        var read = readout();

        var N = 24;
        var seed = [];
        for (var i = 0; i < N; i++) seed.push({ a: Math.random() * Math.PI * 2, b: Math.random() * Math.PI * 2 });

        function stateFor(t) { return t < 0 ? 'صلب' : (t < 100 ? 'سائل' : 'غاز'); }

        function draw() {
            var w = container.clientWidth || 320, h = 230;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);

            var st = stateFor(t);
            // Container
            ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 2;
            ctx.strokeRect(20, 20, w - 40, h - 40);

            var pad = 34;
            var bw = w - 80, bh = h - 80;
            var grid = st === 'صلب' ? 4 : (st === 'سائل' ? 5 : 6);
            var jitter = st === 'صلب' ? 1 : (st === 'سائل' ? 6 : 18);
            var speed = st === 'صلب' ? 0 : (st === 'سائل' ? 0.6 : 1.8);

            ctx.fillStyle = st === 'صلب' ? '#1d4ed8' : (st === 'سائل' ? '#0e7490' : '#b45309');
            for (var i = 0; i < N; i++) {
                var col = i % grid, row = Math.floor(i / grid);
                var x, y;
                if (st === 'غاز') {
                    x = 40 + ((Math.sin(seed[i].a + performance.now() / 900 * speed) + 1) / 2) * bw;
                    y = 40 + ((Math.cos(seed[i].b + performance.now() / 700 * speed) + 1) / 2) * bh;
                } else {
                    x = 40 + (col + 0.5) * (bw / grid) + Math.sin(seed[i].a + performance.now() / 1200) * jitter;
                    y = st === 'سائل'
                        ? 40 + bh - (row + 0.5) * (bh / Math.max(1, Math.ceil(N / grid)))
                        : 40 + (row + 0.5) * (bh / Math.max(1, Math.ceil(N / grid)));
                }
                ctx.beginPath(); ctx.arc(x, y, 7, 0, Math.PI * 2); ctx.fill();
            }
            ctx.fillStyle = '#1f2937'; ctx.font = 'bold 14px system-ui'; ctx.textAlign = 'center';
            ctx.fillText('الحالة: ' + st + '  —  ' + fmt(t) + ' °س', w / 2, h - 8);

            read.innerHTML = '<code>' + st + '</code><code>' + (st === 'صلب' ? 'الجزيئات متراصّة وتهتزّ في مكانها' : st === 'سائل' ? 'الجزيئات متقاربة لكنها تتحرّك' : 'الجزيئات متباعدة وتتحرّك بسرعة') + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'states-of-matter', state: st, t: t });
        }

        var bar = el('div', 'im-controls');
        var l = el('label', 'im-ctrl');
        l.appendChild(el('span', 'im-ctrl-label', 'درجة الحرارة'));
        var s = el('input'); s.type = 'range'; s.min = -20; s.max = 140; s.step = 5; s.value = t;
        s.oninput = function () { t = parseFloat(s.value); draw(); };
        l.appendChild(s); bar.appendChild(l);
        container.appendChild(bar); container.appendChild(read);

        var raf = null;
        function loop() { draw(); raf = requestAnimationFrame(loop); }
        loop();

        return {
            destroy: function () { if (raf) cancelAnimationFrame(raf); container.innerHTML = ''; },
            narrate: function () {
                return ['المادة في الحالة ' + stateFor(t) + ' عند ' + fmt(t) + ' درجة مئوية.',
                    'التسخين يزيد حركة الجزيئات ويباعد بينها، فيتحوّل الصلب إلى سائل ثم إلى غاز.'];
            },
        };
    };

    /* ---------------------------------------------------------------- circuit
     * A simple circuit: series or parallel, with Ohm's law read-outs.
     */
    KINDS['circuit'] = function (container, spec, opts) {
        var p = spec.params || {};
        var mode = p.mode === 'parallel' ? 'parallel' : 'series';
        var v = p.v != null ? p.v : 12;
        var r1 = p.r1 != null ? p.r1 : 4;
        var r2 = p.r2 != null ? p.r2 : 6;

        var s = svg(340, 220, 'im-circuit');
        container.appendChild(s);
        var read = readout();

        function totalR() { return mode === 'series' ? r1 + r2 : (r1 * r2) / (r1 + r2); }

        function draw() {
            s.innerHTML = '';
            var w = 340, h = 220;
            // battery (left), two resistors
            add(s, 'rect', { x: 16, y: 70, width: 16, height: 80, rx: 3, fill: AMBER });
            add(s, 'text', { x: 26, y: 60, 'text-anchor': 'middle', 'font-size': 12, 'font-family': 'system-ui', fill: '#1f2937' }, fmt(v) + ' ف');
            add(s, 'path', { d: 'M24 70 L24 30 L300 30 L300 110', fill: 'none', stroke: GREEN, 'stroke-width': 2.5 });
            add(s, 'path', { d: 'M24 150 L24 195 L300 195 L300 110', fill: 'none', stroke: GREEN, 'stroke-width': 2.5 });

            if (mode === 'series') {
                add(s, 'rect', { x: 130, y: 18, width: 60, height: 24, rx: 4, fill: '#e2e8f0', stroke: GREEN });
                add(s, 'text', { x: 160, y: 34, 'text-anchor': 'middle', 'font-size': 12, 'font-family': 'system-ui' }, 'م1 ' + fmt(r1));
                add(s, 'rect', { x: 230, y: 18, width: 60, height: 24, rx: 4, fill: '#e2e8f0', stroke: GREEN });
                add(s, 'text', { x: 260, y: 34, 'text-anchor': 'middle', 'font-size': 12, 'font-family': 'system-ui' }, 'م2 ' + fmt(r2));
            } else {
                add(s, 'path', { d: 'M160 30 L160 110 L300 110', fill: 'none', stroke: GREEN, 'stroke-width': 2.5 });
                add(s, 'rect', { x: 130, y: 18, width: 60, height: 24, rx: 4, fill: '#e2e8f0', stroke: GREEN });
                add(s, 'text', { x: 160, y: 34, 'text-anchor': 'middle', 'font-size': 12, 'font-family': 'system-ui' }, 'م1 ' + fmt(r1));
                add(s, 'rect', { x: 250, y: 98, width: 60, height: 24, rx: 4, fill: '#e2e8f0', stroke: GREEN });
                add(s, 'text', { x: 280, y: 114, 'text-anchor': 'middle', 'font-size': 12, 'font-family': 'system-ui' }, 'م2 ' + fmt(r2));
            }
            var I = v / totalR();
            add(s, 'text', { x: 170, y: 150, 'text-anchor': 'middle', 'font-size': 14, 'font-family': 'system-ui', 'font-weight': 'bold', fill: RED }, 'ت = ' + fmt(I, 3) + ' أ');

            read.innerHTML = '<code>المقاومة الكلية = ' + fmt(totalR(), 3) + ' Ω</code>' +
                '<code>التيار = ج/م = ' + fmt(I, 3) + ' أمبير</code>' +
                '<code>' + (mode === 'series' ? 'التوالي: نفس التيار، والجهد يتوزّع' : 'التوازي: نفس الجهد، والتيار يتوزّع') + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'circuit', current: I, resistance: totalR() });
        }

        function slider(label, val, min, max, on) {
            var l = el('label', 'im-ctrl');
            l.appendChild(el('span', 'im-ctrl-label', label));
            var s2 = el('input'); s2.type = 'range'; s2.min = min; s2.max = max; s2.step = 1; s2.value = val;
            s2.oninput = function () { on(parseFloat(s2.value)); draw(); };
            l.appendChild(s2); return l;
        }
        var bar = el('div', 'im-controls');
        var pick = el('select', 'im-select');
        [['series', 'توالي'], ['parallel', 'توازي']].forEach(function (o) {
            var op = el('option', null, o[1]); op.value = o[0]; if (o[0] === mode) op.selected = true; pick.appendChild(op);
        });
        pick.onchange = function () { mode = pick.value; draw(); };
        bar.appendChild(pick);
        bar.appendChild(slider('الجهد', v, 1, 24, function (x) { v = x; }));
        bar.appendChild(slider('م1', r1, 1, 20, function (x) { r1 = x; }));
        bar.appendChild(slider('م2', r2, 1, 20, function (x) { r2 = x; }));
        container.appendChild(bar); container.appendChild(read);
        draw();

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () {
                return ['دائرة على ' + (mode === 'series' ? 'التوالي' : 'التوازي') + '.',
                    'المقاومة الكلية ' + fmt(totalR(), 3) + ' أوم، والتيار ' + fmt(v / totalR(), 3) + ' أمبير.',
                    'من قانون أوم: التيار = الجهد ÷ المقاومة.'];
            },
        };
    };

    /* ------------------------------------------------------------------- wave
     * A wave with adjustable amplitude, wavelength and frequency.
     */
    KINDS['wave'] = function (container, spec, opts) {
        var p = spec.params || {};
        var amp = p.amp != null ? p.amp : 1;
        var lambda = p.lambda != null ? p.lambda : 2;
        var speed = p.speed != null ? p.speed : 6;

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '200px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);
        var read = readout();
        var t0 = performance.now();

        function draw() {
            var w = container.clientWidth || 320, h = 200;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            var mid = h / 2, A = (h / 2 - 30) * (amp / 3);

            ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(20, mid); ctx.lineTo(w - 20, mid); ctx.stroke();

            var t = (performance.now() - t0) / 1000 * speed;
            ctx.strokeStyle = GREEN; ctx.lineWidth = 2.5; ctx.beginPath();
            for (var x = 20; x <= w - 20; x++) {
                var y = mid - A * Math.sin((2 * Math.PI / (lambda * 40)) * (x - 20) - t);
                if (x === 20) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            }
            ctx.stroke();
            // amplitude marker
            ctx.strokeStyle = AMBER; ctx.setLineDash([4, 3]); ctx.beginPath();
            ctx.moveTo(30, mid); ctx.lineTo(30, mid - A); ctx.stroke(); ctx.setLineDash([]);
            ctx.fillStyle = AMBER; ctx.font = '12px system-ui'; ctx.fillText('السعة', 34, mid - A - 4);
            // wavelength marker
            ctx.strokeStyle = BLUE; ctx.beginPath();
            ctx.moveTo(20, mid + 22); ctx.lineTo(20 + lambda * 40, mid + 22); ctx.stroke();
            ctx.fillStyle = BLUE; ctx.fillText('الطول الموجي', 24, mid + 36);

            var freq = speed / lambda;
            read.innerHTML = '<code>السعة = ' + fmt(amp) + '</code><code>الطول الموجي = ' + fmt(lambda) + '</code>' +
                '<code>التردّد = السرعة ÷ الطول الموجي = ' + fmt(freq, 2) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'wave', frequency: freq });
        }

        function slider(label, val, min, max, step, on) {
            var l = el('label', 'im-ctrl');
            l.appendChild(el('span', 'im-ctrl-label', label));
            var s = el('input'); s.type = 'range'; s.min = min; s.max = max; s.step = step; s.value = val;
            s.oninput = function () { on(parseFloat(s.value)); };
            l.appendChild(s); return l;
        }
        var bar = el('div', 'im-controls');
        bar.appendChild(slider('السعة', amp, 0.2, 3, 0.2, function (x) { amp = x; }));
        bar.appendChild(slider('الطول الموجي', lambda, 1, 5, 0.5, function (x) { lambda = x; }));
        bar.appendChild(slider('السرعة', speed, 1, 14, 1, function (x) { speed = x; }));
        container.appendChild(bar); container.appendChild(read);

        var raf = null;
        function loop() { draw(); raf = requestAnimationFrame(loop); }
        loop();

        return {
            destroy: function () { if (raf) cancelAnimationFrame(raf); container.innerHTML = ''; },
            narrate: function () {
                return ['الموجة: السعة ' + fmt(amp) + ' والطول الموجي ' + fmt(lambda) + '.',
                    'كلما قصر الطول الموجي زاد التردّد، لأن السرعة = التردّد × الطول الموجي.'];
            },
        };
    };

    /* -------------------------------------------------------------- bar-chart
     * Data, mean and median — statistics lessons.
     */
    KINDS['bar-chart'] = function (container, spec, opts) {
        var p = spec.params || {};
        var labels = p.labels || ['أ', 'ب', 'ج', 'د'];
        var data = (p.data || [4, 7, 3, 6]).slice();

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '230px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);
        var read = readout();

        function mean() { return data.reduce(function (a, b) { return a + b; }, 0) / data.length; }
        function median() {
            var s = data.slice().sort(function (a, b) { return a - b; });
            var m = Math.floor(s.length / 2);
            return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
        }

        function draw() {
            var w = container.clientWidth || 320, h = 230;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            var max = Math.max.apply(null, data) || 1;
            var bw = (w - 60) / data.length;

            data.forEach(function (v, i) {
                var bh = (v / max) * (h - 70);
                var x = 40 + i * bw + bw * 0.15, y = h - 40 - bh;
                ctx.fillStyle = GREEN;
                ctx.fillRect(x, y, bw * 0.7, bh);
                ctx.fillStyle = '#1f2937'; ctx.font = '12px system-ui'; ctx.textAlign = 'center';
                ctx.fillText(String(v), x + bw * 0.35, y - 4);
                ctx.fillText(labels[i] || String(i + 1), x + bw * 0.35, h - 22);
            });
            // mean line
            var my = h - 40 - (mean() / max) * (h - 70);
            ctx.strokeStyle = AMBER; ctx.setLineDash([5, 4]); ctx.beginPath();
            ctx.moveTo(30, my); ctx.lineTo(w - 20, my); ctx.stroke(); ctx.setLineDash([]);
            ctx.fillStyle = AMBER; ctx.textAlign = 'left'; ctx.fillText('المتوسط ' + fmt(mean()), 34, my - 5);

            read.innerHTML = '<code>المتوسط = ' + fmt(mean()) + '</code><code>الوسيط = ' + fmt(median()) + '</code><code>المدى = ' + (Math.max.apply(null, data) - Math.min.apply(null, data)) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'bar-chart', mean: mean(), median: median() });
        }

        container.appendChild(read);
        draw();
        var ro = window.ResizeObserver ? new ResizeObserver(draw) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', draw);

        return {
            destroy: function () { if (ro) ro.disconnect(); else window.removeEventListener('resize', draw); container.innerHTML = ''; },
            narrate: function () {
                return ['البيانات: ' + data.join('، ') + '.',
                    'المتوسط يساوي ' + fmt(mean()) + '، والوسيط يساوي ' + fmt(median()) + '.'];
            },
        };
    };

    /* ------------------------------------------------------------ fractions
     * A bar split into equal parts with a shaded fraction. Covers الكسور
     * المتكافئة، جمع وطرح الكسور، المقارنة بين الكسور.
     */
    KINDS['fractions'] = function (container, spec, opts) {
        var p = spec.params || {};
        var den = p.den != null ? p.den : 4;
        var num = p.num != null ? p.num : 1;

        var s = svg(340, 120, 'im-fractions');
        container.appendChild(s);
        var read = readout();

        function draw() {
            s.innerHTML = '';
            var w = 340, x0 = 20, y0 = 30, bw = (w - 40) / den, bh = 46;
            for (var i = 0; i < den; i++) {
                add(s, 'rect', {
                    x: x0 + i * bw, y: y0, width: bw, height: bh,
                    fill: i < num ? GREEN : '#f1f5f9', stroke: '#0f172a', 'stroke-width': 1.5,
                });
            }
            add(s, 'text', { x: w / 2, y: y0 + bh + 26, 'text-anchor': 'middle', 'font-size': 16, 'font-family': 'system-ui', 'font-weight': 'bold', fill: '#1f2937' },
                num + ' / ' + den + '   =   ' + fmt(num / den, 3));
            read.innerHTML = '<code>الجزء الملوّن: ' + num + ' من ' + den + '</code><code>القيمة العشرية: ' + fmt(num / den, 3) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'fractions', num: num, den: den });
        }

        var bar = el('div', 'im-controls');
        var ld = el('label', 'im-ctrl');
        ld.appendChild(el('span', 'im-ctrl-label', 'المقام'));
        var sd = el('input'); sd.type = 'range'; sd.min = 2; sd.max = 10; sd.step = 1; sd.value = den;
        sd.oninput = function () { den = parseInt(sd.value, 10); if (num > den) { num = den; sn.value = num; } draw(); };
        ld.appendChild(sd);
        var ln = el('label', 'im-ctrl');
        ln.appendChild(el('span', 'im-ctrl-label', 'البسط'));
        var sn = el('input'); sn.type = 'range'; sn.min = 0; sn.max = 10; sn.step = 1; sn.value = num;
        sn.oninput = function () { num = Math.min(parseInt(sn.value, 10), den); sn.value = num; draw(); };
        ln.appendChild(sn);
        bar.appendChild(ld); bar.appendChild(ln);
        container.appendChild(bar); container.appendChild(read);
        draw();

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () {
                return ['الكسر هو ' + num + ' على ' + den + '.',
                    'أي ' + num + ' من ' + den + ' أجزاء متساوية، وقيمته ' + fmt(num / den, 3) + '.'];
            },
        };
    };

    /* ----------------------------------------------------------------- angles
     * An angle with its degree measure and its type (حادّة، قائمة، منفرجة).
     */
    KINDS['angles'] = function (container, spec, opts) {
        var p = spec.params || {};
        var deg = p.deg != null ? p.deg : 60;

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%'; canvas.style.height = '240px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);
        var read = readout();

        function typeFor(d) {
            if (d < 90) return 'حادّة';
            if (d === 90) return 'قائمة';
            if (d < 180) return 'منفرجة';
            return 'مستقيمة';
        }

        function draw() {
            var w = container.clientWidth || 320, h = 240;
            canvas.width = Math.round(w * DPR); canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            var cx = w / 2 - 40, cy = h - 50, R = Math.min(w, h) * 0.55;

            ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 1;
            ctx.beginPath(); ctx.arc(cx, cy, R, Math.PI, 2 * Math.PI); ctx.stroke();

            ctx.strokeStyle = GREEN; ctx.lineWidth = 3;
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + R, cy); ctx.stroke();
            var rad = (deg * Math.PI) / 180;
            ctx.beginPath(); ctx.moveTo(cx, cy);
            ctx.lineTo(cx + R * Math.cos(-rad), cy + R * Math.sin(-rad)); ctx.stroke();

            ctx.strokeStyle = AMBER; ctx.lineWidth = 2;
            ctx.beginPath(); ctx.arc(cx, cy, 42, -rad, 0); ctx.stroke();
            ctx.fillStyle = AMBER; ctx.font = 'bold 14px system-ui'; ctx.textAlign = 'center';
            ctx.fillText(deg + '°', cx + 60 * Math.cos(-rad / 2), cy + 60 * Math.sin(-rad / 2) + 5);

            read.innerHTML = '<code>' + deg + '°</code><code>زاوية ' + typeFor(deg) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'angles', deg: deg, type: typeFor(deg) });
        }

        var bar = el('div', 'im-controls');
        var l = el('label', 'im-ctrl');
        l.appendChild(el('span', 'im-ctrl-label', 'الزاوية'));
        var s2 = el('input'); s2.type = 'range'; s2.min = 0; s2.max = 180; s2.step = 5; s2.value = deg;
        s2.oninput = function () { deg = parseInt(s2.value, 10); draw(); };
        l.appendChild(s2); bar.appendChild(l);
        container.appendChild(bar); container.appendChild(read);
        draw();
        var ro = window.ResizeObserver ? new ResizeObserver(draw) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', draw);

        return {
            destroy: function () { if (ro) ro.disconnect(); else window.removeEventListener('resize', draw); container.innerHTML = ''; },
            narrate: function () {
                return ['الزاوية قياسها ' + deg + ' درجة، وهي زاوية ' + typeFor(deg) + '.',
                    'الزاوية القائمة ٩٠ درجة، والحادّة أقل منها، والمنفرجة أكبر منها.'];
            },
        };
    };

    window.InteractiveScience = {
        kinds: Object.keys(KINDS),
        register: function (Interactive) {
            if (!Interactive || !Interactive.register) return;
            Object.keys(KINDS).forEach(function (k) { Interactive.register(k, KINDS[k]); });
        },
    };
    if (window.Interactive) window.InteractiveScience.register(window.Interactive);
})();
