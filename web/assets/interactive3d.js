/* محرّك النماذج ثلاثية الأبعاد — Interactive 3-D model engine.
 *
 * Companion to interactive.js. Where that file draws flat figures, this one
 * builds real three-dimensional scenes: solids, solids of revolution, vectors
 * in space and surfaces z = f(x, y). The learner orbits each scene with a
 * finger and the teacher narrates whatever is currently on screen.
 *
 * Why not WebGL: the platform is meant to run on the low-end Android phones
 * common in Yemeni classrooms, where WebGL contexts are often missing or
 * software-rendered. This renderer therefore uses the 2-D canvas with a
 * perspective projection, back-face culling, painter's-algorithm depth sorting
 * and flat shading — enough for solid geometry and fast enough everywhere.
 *
 * Everything is computed in *world* units (1 unit = 1 textbook unit) and only
 * projected at draw time, so a shape stays geometrically correct at any size.
 */
(function () {
    'use strict';

    var DPR = Math.min(window.devicePixelRatio || 1, 2);
    var LIGHT = (function () { var l = [0.45, 0.85, 0.35]; var m = Math.hypot(l[0], l[1], l[2]); return [l[0] / m, l[1] / m, l[2] / m]; })();

    // ------------------------------------------------------------------ vectors
    function sub(a, b) { return [a[0] - b[0], a[1] - b[1], a[2] - b[2]]; }
    function cross(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
    function dot(a, b) { return a[0] * b[0] + a[1] * b[1] + a[2] * b[2]; }
    function unit(a) { var m = Math.hypot(a[0], a[1], a[2]) || 1; return [a[0] / m, a[1] / m, a[2] / m]; }

    // Convex hull (monotone chain) of 2-D points, used for the contact shadow.
    function convexHull(pts) {
        var p = pts.slice().sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; });
        if (p.length < 3) return p;
        function half(seq) {
            var out = [];
            seq.forEach(function (q) {
                while (out.length >= 2) {
                    var o = out[out.length - 2], r = out[out.length - 1];
                    if ((r[0] - o[0]) * (q[1] - o[1]) - (r[1] - o[1]) * (q[0] - o[0]) <= 0) out.pop();
                    else break;
                }
                out.push(q);
            });
            out.pop();
            return out;
        }
        return half(p).concat(half(p.slice().reverse()));
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function fmt(x, d) {
        if (!isFinite(x)) return '∞';
        var s = x.toFixed(d == null ? 2 : d);
        return s.replace(/\.?0+$/, '') || '0';
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
        return wrap;
    }

    function pick(label, options, value, onChange) {
        var wrap = el('label', 'im-ctrl');
        wrap.appendChild(el('span', 'im-ctrl-label', label));
        var sel = el('select');
        options.forEach(function (o) {
            var opt = el('option', null, o[1]);
            opt.value = o[0];
            if (o[0] === value) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.onchange = function () { onChange(sel.value); };
        wrap.appendChild(sel);
        return wrap;
    }

    // ------------------------------------------------------------------ meshes
    // A mesh is { v: [[x,y,z], ...], f: [[i,j,k,...], ...], color: [r,g,b] }.
    function mesh(v, f, color) { return { v: v, f: f, color: color || [15, 118, 110] }; }

    function box(a, b, c) {
        var x = a / 2, y = b / 2, z = c / 2;
        return mesh([
            [-x, -y, -z], [x, -y, -z], [x, y, -z], [-x, y, -z],
            [-x, -y, z], [x, -y, z], [x, y, z], [-x, y, z],
        ], [
            [0, 1, 2, 3], [4, 5, 6, 7], [0, 1, 5, 4],
            [2, 3, 7, 6], [1, 2, 6, 5], [0, 3, 7, 4],
        ]);
    }

    function ring(n, r, y) {
        var out = [];
        for (var i = 0; i < n; i++) {
            var t = (i / n) * Math.PI * 2;
            out.push([r * Math.cos(t), y, r * Math.sin(t)]);
        }
        return out;
    }

    function prism(n, r, h) {
        var v = ring(n, r, -h / 2).concat(ring(n, r, h / 2));
        var f = [];
        for (var i = 0; i < n; i++) {
            var j = (i + 1) % n;
            f.push([i, j, n + j, n + i]);       // side
        }
        var bottom = [], top = [];
        for (var k = n - 1; k >= 0; k--) bottom.push(k);
        for (var m = 0; m < n; m++) top.push(n + m);
        f.push(bottom); f.push(top);
        return mesh(v, f);
    }

    function pyramid(n, r, h) {
        var v = ring(n, r, -h / 2);
        v.push([0, h / 2, 0]);
        var f = [];
        for (var i = 0; i < n; i++) f.push([i, (i + 1) % n, n]);
        var base = [];
        for (var k = n - 1; k >= 0; k--) base.push(k);
        f.push(base);
        return mesh(v, f);
    }

    function cylinder(r, h, seg) {
        seg = seg || 24;
        var v = ring(seg, r, -h / 2).concat(ring(seg, r, h / 2));
        var f = [];
        for (var i = 0; i < seg; i++) {
            var j = (i + 1) % seg;
            f.push([i, j, seg + j, seg + i]);
        }
        var bottom = [], top = [];
        for (var k = seg - 1; k >= 0; k--) bottom.push(k);
        for (var m = 0; m < seg; m++) top.push(seg + m);
        f.push(bottom); f.push(top);
        return mesh(v, f);
    }

    function cone(r, h, seg) {
        seg = seg || 24;
        var v = ring(seg, r, -h / 2);
        v.push([0, h / 2, 0]);
        var f = [];
        for (var i = 0; i < seg; i++) f.push([i, (i + 1) % seg, seg]);
        var base = [];
        for (var k = seg - 1; k >= 0; k--) base.push(k);
        f.push(base);
        return mesh(v, f);
    }

    function sphere(r, seg) {
        seg = seg || 16;
        var v = [], f = [];
        var rings = Math.max(6, Math.round(seg * 0.75));
        v.push([0, r, 0]);                                   // 0: north pole
        for (var i = 1; i < rings; i++) {
            var phi = (i / rings) * Math.PI;
            var y = r * Math.cos(phi), rad = r * Math.sin(phi);
            for (var j = 0; j < seg; j++) {
                var t = (j / seg) * Math.PI * 2;
                v.push([rad * Math.cos(t), y, rad * Math.sin(t)]);
            }
        }
        v.push([0, -r, 0]);                                  // last: south pole
        var south = v.length - 1;
        function idx(i, j) { return 1 + (i - 1) * seg + (j % seg); }
        for (var jj = 0; jj < seg; jj++) f.push([0, idx(1, jj), idx(1, jj + 1)]);
        for (var ii = 1; ii < rings - 1; ii++) {
            for (var kk = 0; kk < seg; kk++) {
                f.push([idx(ii, kk), idx(ii + 1, kk), idx(ii + 1, kk + 1), idx(ii, kk + 1)]);
            }
        }
        for (var m = 0; m < seg; m++) f.push([south, idx(rings - 1, m + 1), idx(rings - 1, m)]);
        return mesh(v, f);
    }

    function tetra(a) {
        var s = a / Math.sqrt(2);
        return mesh([
            [s, s, s], [s, -s, -s], [-s, s, -s], [-s, -s, s],
        ], [[0, 1, 2], [0, 3, 1], [0, 2, 3], [1, 3, 2]]);
    }

    function octa(a) {
        var s = a / Math.sqrt(2);
        return mesh([
            [s, 0, 0], [-s, 0, 0], [0, s, 0], [0, -s, 0], [0, 0, s], [0, 0, -s],
        ], [[0, 2, 4], [2, 1, 4], [1, 3, 4], [3, 0, 4], [2, 0, 5], [1, 2, 5], [3, 1, 5], [0, 3, 5]]);
    }

    // A solid of revolution approximated by `n` stacked cylinders (a Riemann
    // sum in three dimensions), centred on the x-axis.
    function revolution(fn, a, b, n, seg, color) {
        var v = [], f = [];
        var dx = (b - a) / n;
        var rings = [];
        for (var i = 0; i <= n; i++) {
            var x = a + i * dx;
            var r = Math.max(0, fn(Math.min(b, Math.max(a, x))));
            var start = v.length;
            for (var j = 0; j < seg; j++) {
                var t = (j / seg) * Math.PI * 2;
                v.push([x, r * Math.cos(t), r * Math.sin(t)]);
            }
            rings.push(start);
        }
        for (var k = 0; k < n; k++) {
            var s0 = rings[k], s1 = rings[k + 1];
            for (var m = 0; m < seg; m++) {
                var q = (m + 1) % seg;
                f.push([s0 + m, s0 + q, s1 + q, s1 + m]);
            }
            // Caps at the two ends close the solid.
            if (k === 0) {
                var capA = [];
                for (var p = seg - 1; p >= 0; p--) capA.push(s0 + p);
                f.push(capA);
            }
            if (k === n - 1) {
                var capB = [];
                for (var w = 0; w < seg; w++) capB.push(s1 + w);
                f.push(capB);
            }
        }
        return mesh(v, f, color);
    }

    // A surface z = f(x, y) over a square grid.
    function surface(fn, range, n, color) {
        var v = [], f = [];
        for (var i = 0; i <= n; i++) {
            var x = -range + (2 * range * i) / n;
            for (var j = 0; j <= n; j++) {
                var y = -range + (2 * range * j) / n;
                v.push([x, fn(x, y), y]);
            }
        }
        var row = n + 1;
        for (var a = 0; a < n; a++) {
            for (var b = 0; b < n; b++) {
                f.push([a * row + b, a * row + b + 1, (a + 1) * row + b + 1, (a + 1) * row + b]);
            }
        }
        return mesh(v, f, color);
    }

    // ------------------------------------------------------------------ camera
    function Camera(opts) {
        this.yaw = opts.yaw != null ? opts.yaw : 0.7;
        this.pitch = opts.pitch != null ? opts.pitch : 0.42;
        this.dist = opts.dist != null ? opts.dist : 9;
        this.target = opts.target || [0, 0, 0];
        this.fov = (opts.fov || 42) * Math.PI / 180;
    }
    Camera.prototype.eye = function () {
        var cp = Math.cos(this.pitch);
        return [
            this.target[0] + this.dist * cp * Math.sin(this.yaw),
            this.target[1] + this.dist * Math.sin(this.pitch),
            this.target[2] + this.dist * cp * Math.cos(this.yaw),
        ];
    };
    Camera.prototype.basis = function () {
        var eye = this.eye();
        var f = unit(sub(this.target, eye));
        var r = unit(cross(f, [0, 1, 0]));
        var u = cross(r, f);
        return { eye: eye, f: f, r: r, u: u };
    };
    // Rotate around the target, keeping the pitch inside the poles.
    Camera.prototype.orbit = function (dx, dy) {
        this.yaw -= dx * 0.008;
        this.pitch = Math.max(-1.45, Math.min(1.45, this.pitch + dy * 0.008));
    };
    Camera.prototype.zoom = function (factor) {
        this.dist = Math.max(3, Math.min(30, this.dist * factor));
    };

    // ------------------------------------------------------------------ scene
    // Draws a list of meshes plus optional line segments and a ground grid.
    function Scene(container, opts) {
        opts = opts || {};
        this.height = opts.height || 340;
        this.camera = new Camera(opts);
        this.meshes = [];
        this.lines = [];
        this.grid = opts.grid !== false;
        this.canvas = el('canvas', 'im-canvas im-canvas3d');
        this.canvas.style.width = '100%';
        this.canvas.style.height = this.height + 'px';
        this.canvas.setAttribute('role', 'img');
        container.appendChild(this.canvas);
        this.ctx = this.canvas.getContext('2d');
        this.container = container;

        var self = this;
        this.W = 320;
        this._resize = function () { self.resize(); };
        this._ro = null;
        if (window.ResizeObserver) {
            this._ro = new ResizeObserver(this._resize);
            this._ro.observe(container);
        } else {
            window.addEventListener('resize', this._resize);
        }

        // Orbit by dragging; pinch or wheel to zoom. Auto-rotation pauses while
        // the learner is dragging, then resumes, so the solid always reads as 3-D.
        var drag = null;
        function pos(ev) { var r = self.canvas.getBoundingClientRect(); var p = ev.touches ? ev.touches[0] : ev; return { x: p.clientX - r.left, y: p.clientY - r.top }; }
        function down(ev) { drag = pos(ev); self.pauseSpin(); if (ev.cancelable) ev.preventDefault(); }
        function move(ev) {
            if (!drag) return;
            var p = pos(ev);
            self.camera.orbit(p.x - drag.x, p.y - drag.y);
            drag = p;
            self.draw();
            if (ev.cancelable) ev.preventDefault();
        }
        function up() { if (drag) { drag = null; self.resumeSpin(); } }
        this.canvas.addEventListener('mousedown', down);
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', up);
        this.canvas.addEventListener('touchstart', down, { passive: false });
        this.canvas.addEventListener('touchmove', move, { passive: false });
        window.addEventListener('touchend', up);
        this.canvas.addEventListener('wheel', function (ev) {
            self.camera.zoom(ev.deltaY > 0 ? 1.08 : 0.93);
            self.draw();
            ev.preventDefault();
        }, { passive: false });
        this.canvas.style.cursor = 'grab';

        // Auto-rotation: gentle, respectful of reduced-motion, and toggleable.
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.autoRotate = opts.autoRotate !== false && !reduce; // the learner's wish
        this._raf = null;
        this._spinToggle = el('button', 'im-spin-toggle', this.autoRotate ? '⏸ إيقاف الدوران' : '▶ تدوير تلقائي');
        this._spinToggle.type = 'button';
        this._spinToggle.addEventListener('click', function () {
            self.autoRotate = !self.autoRotate;
            self._spinToggle.textContent = self.autoRotate ? '⏸ إيقاف الدوران' : '▶ تدوير تلقائي';
            if (self.autoRotate) self.resumeSpin(); else self.pauseSpin();
        });
        container.appendChild(this._spinToggle);

        this.resize();
        this.resumeSpin();
    }

    // A slow orbit loop that only runs while enabled and not being dragged.
    Scene.prototype.resumeSpin = function () {
        if (!this.autoRotate || this._raf) return;
        var self = this, last = performance.now();
        function step(t) {
            if (!self.autoRotate) { self._raf = null; return; }
            var dt = Math.min(64, t - last); last = t;
            self.camera.yaw += dt * 0.00022;
            self.draw();
            self._raf = requestAnimationFrame(step);
        }
        this._raf = requestAnimationFrame(step);
    };
    // Stop the loop but keep the learner's auto-rotate preference.
    Scene.prototype.pauseSpin = function () {
        if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
    };

    Scene.prototype.resize = function () {
        this.W = this.container.clientWidth || 320;
        this.canvas.width = Math.round(this.W * DPR);
        this.canvas.height = Math.round(this.height * DPR);
        this.ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        this.draw();
    };

    Scene.prototype.add = function (m) { this.meshes.push(m); return this; };
    Scene.prototype.line = function (a, b, color, width) { this.lines.push({ a: a, b: b, color: color || '#94a3b8', w: width || 1 }); return this; };
    Scene.prototype.clear = function () { this.meshes = []; this.lines = []; return this; };

    Scene.prototype.draw = function () {
        var ctx = this.ctx, W = this.W, H = this.height;
        ctx.clearRect(0, 0, W, H);

        // Studio backdrop: a soft vertical gradient so the solid reads as an
        // object standing on a surface rather than a flat diagram.
        var bg = ctx.createLinearGradient(0, 0, 0, H);
        bg.addColorStop(0, '#f8fafc');
        bg.addColorStop(1, '#e8eef6');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, W, H);

        var b = this.camera.basis();
        var focal = (H / 2) / Math.tan(this.camera.fov / 2);
        var self = this;

        function project(p) {
            var rel = sub(p, b.eye);
            var z = dot(rel, b.f);
            if (z <= 0.08) return null;
            return { x: W / 2 + (dot(rel, b.r) / z) * focal, y: H / 2 - (dot(rel, b.u) / z) * focal, z: z };
        }

        // Ground plane grid on y = 0, with the two principal axes emphasised.
        if (this.grid) {
            var span = 5;
            ctx.lineWidth = 1;
            for (var i = -span; i <= span; i++) {
                var p1 = project([i, 0, -span]), p2 = project([i, 0, span]);
                var p3 = project([-span, 0, i]), p4 = project([span, 0, i]);
                ctx.strokeStyle = i === 0 ? '#cbd5e1' : '#e2e8f0';
                if (p1 && p2) { ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y); ctx.stroke(); }
                if (p3 && p4) { ctx.beginPath(); ctx.moveTo(p3.x, p3.y); ctx.lineTo(p4.x, p4.y); ctx.stroke(); }
            }

            // Contact shadow: the convex hull of every vertex dropped to y = 0,
            // filled softly so the solid appears to rest on the ground.
            var ground = [];
            this.meshes.forEach(function (m) { m.v.forEach(function (v) { var s = project([v[0], 0, v[2]]); if (s) ground.push([s.x, s.y]); }); });
            if (ground.length >= 3) {
                var hull = convexHull(ground);
                if (hull.length >= 3) {
                    ctx.beginPath();
                    ctx.moveTo(hull[0][0], hull[0][1]);
                    for (var h = 1; h < hull.length; h++) ctx.lineTo(hull[h][0], hull[h][1]);
                    ctx.closePath();
                    var sc = { x: 0, y: 0 };
                    hull.forEach(function (q) { sc.x += q[0]; sc.y += q[1]; });
                    sc.x /= hull.length; sc.y /= hull.length;
                    var rad = Math.max.apply(null, hull.map(function (q) { return Math.hypot(q[0] - sc.x, q[1] - sc.y); })) || 1;
                    var sg = ctx.createRadialGradient(sc.x, sc.y, 0, sc.x, sc.y, rad * 1.15);
                    sg.addColorStop(0, 'rgba(15,23,42,.22)');
                    sg.addColorStop(0.7, 'rgba(15,23,42,.10)');
                    sg.addColorStop(1, 'rgba(15,23,42,0)');
                    ctx.fillStyle = sg;
                    ctx.fill();
                }
            }
        }

        // Collect every face with its camera depth so nearer faces win.
        var faces = [];
        this.meshes.forEach(function (m) {
            m.f.forEach(function (idx) {
                var pts = [], depth = 0, world = [];
                for (var i = 0; i < idx.length; i++) {
                    var p = m.v[idx[i]];
                    var sp = project(p);
                    if (!sp) return;
                    pts.push(sp);
                    world.push(p);
                    depth += sp.z;
                }
                if (pts.length !== idx.length) return;
                depth /= idx.length;

                var n = unit(cross(sub(world[1], world[0]), sub(world[2], world[0])));
                var center = [0, 0, 0];
                world.forEach(function (w) { center[0] += w[0]; center[1] += w[1]; center[2] += w[2]; });
                center = [center[0] / world.length, center[1] / world.length, center[2] / world.length];
                // Back-face culling: a face whose normal points away from the
                // camera cannot be seen on a closed solid.
                if (dot(n, unit(sub(b.eye, center))) <= 0) return;

                var shade = 0.34 + 0.66 * Math.max(0, dot(n, LIGHT));
                faces.push({ pts: pts, depth: depth, shade: shade, color: m.color });
            });
        });

        faces.sort(function (a, c) { return c.depth - a.depth; });

        faces.forEach(function (face) {
            var c = face.color, s = face.shade;
            ctx.beginPath();
            ctx.moveTo(face.pts[0].x, face.pts[0].y);
            for (var i = 1; i < face.pts.length; i++) ctx.lineTo(face.pts[i].x, face.pts[i].y);
            ctx.closePath();
            ctx.fillStyle = 'rgb(' + Math.round(c[0] * s) + ',' + Math.round(c[1] * s) + ',' + Math.round(c[2] * s) + ')';
            ctx.fill();
            // A faint bright edge gives each facet a crisp, printed quality.
            ctx.strokeStyle = 'rgba(255,255,255,.35)';
            ctx.lineWidth = 1;
            ctx.stroke();
        });

        // Lines (axes, vectors, projections) are drawn on top of the solids.
        this.lines.forEach(function (l) {
            var p1 = project(l.a), p2 = project(l.b);
            if (!p1 || !p2) return;
            ctx.strokeStyle = l.color;
            ctx.lineWidth = l.w;
            ctx.beginPath();
            ctx.moveTo(p1.x, p1.y);
            ctx.lineTo(p2.x, p2.y);
            ctx.stroke();
        });
        void self;
    };

    Scene.prototype.destroy = function () {
        this.pauseSpin();
        if (this._ro) this._ro.disconnect();
        else window.removeEventListener('resize', this._resize);
        this.container.innerHTML = '';
    };

    // ------------------------------------------------------------------ helpers
    var BLUE = [37, 99, 235], TEAL = [13, 148, 136], AMBER = [217, 119, 6], ROSE = [225, 29, 72], SLATE = [100, 116, 139];

    function readout() { return el('div', 'im-readout'); }
    function controls() { return el('div', 'im-controls'); }

    // ------------------------------------------------------------------ models
    var KINDS = {};

    /* 1) المجسّمات — a solid whose dimensions the learner changes. */
    KINDS['solid-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var shape = p.shape || 'cube';
        var a = p.a != null ? p.a : 2;     // edge / radius
        var h = p.h != null ? p.h : 3;     // height
        var n = p.n != null ? p.n : 6;     // sides for prisms & pyramids

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 11, yaw: 0.75, pitch: 0.45 });

        var SHAPES = [
            ['cube', 'مكعب'], ['prism', 'منشور'], ['pyramid', 'هرم'],
            ['cylinder', 'أسطوانة'], ['cone', 'مخروط'], ['sphere', 'كرة'],
            ['tetra', 'رباعي أوجه منتظم'], ['octa', 'ثماني أوجه منتظم'],
        ];

        function baseArea() {
            if (shape === 'cube') return a * a;
            if (shape === 'prism' || shape === 'pyramid') return (n / 2) * a * a * Math.sin(2 * Math.PI / n);
            if (shape === 'cylinder' || shape === 'cone') return Math.PI * a * a;
            if (shape === 'sphere') return 0;
            if (shape === 'tetra') return (Math.sqrt(3) / 4) * a * a;
            return 2 * Math.sqrt(3) * a * a; // octa
        }

        function volume() {
            switch (shape) {
                case 'cube': return a * a * a;
                case 'prism': return baseArea() * h;
                case 'pyramid': return baseArea() * h / 3;
                case 'cylinder': return Math.PI * a * a * h;
                case 'cone': return Math.PI * a * a * h / 3;
                case 'sphere': return (4 / 3) * Math.PI * a * a * a;
                case 'tetra': return a * a * a / (6 * Math.SQRT2);
                default: return (Math.SQRT2 / 3) * a * a * a; // octa
            }
        }

        function area() {
            switch (shape) {
                case 'cube': return 6 * a * a;
                case 'prism': return 2 * baseArea() + n * (2 * a * Math.sin(Math.PI / n)) * h;
                case 'pyramid': {
                    var apothem = a * Math.cos(Math.PI / n);
                    var slant = Math.sqrt(h * h + apothem * apothem);
                    return baseArea() + n * 0.5 * (2 * a * Math.sin(Math.PI / n)) * slant;
                }
                case 'cylinder': return 2 * Math.PI * a * a + 2 * Math.PI * a * h;
                case 'cone': return Math.PI * a * a + Math.PI * a * Math.sqrt(a * a + h * h);
                case 'sphere': return 4 * Math.PI * a * a;
                case 'tetra': return Math.sqrt(3) * a * a;
                default: return 2 * Math.sqrt(3) * a * a; // octa
            }
        }

        function build() {
            scene.clear();
            if (shape === 'cube') scene.add(box(a, a, a));
            else if (shape === 'prism') scene.add(prism(Math.round(n), a, h));
            else if (shape === 'pyramid') scene.add(pyramid(Math.round(n), a, h));
            else if (shape === 'cylinder') scene.add(cylinder(a, h, 32));
            else if (shape === 'cone') scene.add(cone(a, h, 32));
            else if (shape === 'sphere') scene.add(sphere(a, 24));
            else if (shape === 'tetra') scene.add(tetra(a));
            else scene.add(octa(a));
            scene.meshes.forEach(function (m) { m.color = TEAL; });
            var H = Math.max(volume() / Math.max(0.001, baseArea()), a) * 0.9 + 2;
            scene.camera.dist = Math.max(6, H * 2.2);
            scene.camera.target = [0, 0, 0];
        }

        function update() {
            build();
            scene.draw();
            read.innerHTML =
                '<code>الحجم = ' + fmt(volume()) + ' وحدة³</code>' +
                '<code>المساحة الكلية = ' + fmt(area()) + ' وحدة²</code>' +
                '<code>مساحة القاعدة = ' + fmt(baseArea()) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'solid-3d', shape: shape, volume: volume(), area: area() });
        }

        container.appendChild(pick('المجسّم', SHAPES, shape, function (v) { shape = v; rebuild(); }));
        container.appendChild(bar);
        var sizeCtl, hCtl, nCtl;
        function rebuild() {
            bar.innerHTML = '';
            var hasSides = shape === 'prism' || shape === 'pyramid';
            var hasHeight = shape === 'prism' || shape === 'pyramid' || shape === 'cylinder' || shape === 'cone';
            var dimLabel = (shape === 'cube') ? 'طول الحرف' : (shape === 'sphere' ? 'نصف القطر' : 'نصف قطر القاعدة');
            sizeCtl = slider(dimLabel, 0.5, 4, 0.1, a, function (v) { a = v; update(); });
            bar.appendChild(sizeCtl);
            if (hasHeight) { hCtl = slider('الارتفاع', 0.5, 6, 0.1, h, function (v) { h = v; update(); }); bar.appendChild(hCtl); }
            if (hasSides) { nCtl = slider('عدد أضلاع القاعدة', 3, 12, 1, n, function (v) { n = v; update(); }); bar.appendChild(nCtl); }
            update();
        }
        rebuild();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'هذا مجسّم: ' + (SHAPES.filter(function (s) { return s[0] === shape; })[0] || ['', 'مجسّم'])[1] + '.',
                    'حجمه يساوي ' + fmt(volume()) + ' وحدة مكعبة، ومساحته الكلية ' + fmt(area()) + ' وحدة مربعة.',
                    'اسحب المجسّم لتدويره ورؤيته من كل الجهات؛ الحجم لا يتغير مهما أدرته.',
                ];
            },
        };
    };

    /* 2) الحجوم الدورانية — rotate a curve's region and watch the discs add up. */
    KINDS['solid-of-revolution'] = function (container, spec, opts) {
        var p = spec.params || {};
        var fnName = p.fn || 'x^2';
        var a = p.a != null ? p.a : 0;
        var b = p.b != null ? p.b : 2;
        var n = p.n != null ? p.n : 6;

        var FNS = {
            'x^2': { f: function (x) { return x * x; }, exact: function (a, b) { return Math.PI * (Math.pow(b, 5) - Math.pow(a, 5)) / 5; }, label: 'د(س) = س²' },
            'sqrt': { f: function (x) { return Math.sqrt(Math.max(0, x)); }, exact: function (a, b) { return Math.PI * (b * b - a * a) / 2; }, label: 'د(س) = √س' },
            '1/x': { f: function (x) { return 1 / x; }, exact: function (a, b) { return Math.PI * (1 / a - 1 / b); }, label: 'د(س) = ١/س' },
            'sin': { f: function (x) { return Math.sin(x); }, exact: null, label: 'د(س) = جا س' },
        };

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 12, yaw: 0.9, pitch: 0.4 });

        function approx() {
            var f = FNS[fnName].f, dx = (b - a) / n, sum = 0;
            for (var i = 0; i < n; i++) {
                var mid = a + (i + 0.5) * dx;
                sum += Math.PI * Math.pow(f(mid), 2) * dx;
            }
            return sum;
        }

        function build() {
            scene.clear();
            scene.add(revolution(FNS[fnName].f, a, b, Math.round(n), 28, AMBER));
            scene.camera.target = [(a + b) / 2, 0, 0];
            scene.camera.dist = Math.max(8, (b - a) * 2.6 + 5);
        }

        function update() {
            build();
            scene.draw();
            var ex = FNS[fnName].exact ? FNS[fnName].exact(a, b) : null;
            read.innerHTML =
                '<code>الدالة: ' + FNS[fnName].label + ' على [' + fmt(a) + ' ، ' + fmt(b) + ']</code>' +
                '<code>عدد الأقراص ن = ' + Math.round(n) + '</code>' +
                '<code>الحجم التقريبي ≈ ' + fmt(approx()) + '</code>' +
                (ex != null ? '<code>الحجم الحقيقي = ' + fmt(ex) + '</code>' : '');
            if (opts && opts.onChange) opts.onChange({ kind: 'solid-of-revolution', n: n, approx: approx(), exact: ex });
        }

        container.appendChild(pick('الدالة', [['x^2', 'د(س) = س²'], ['sqrt', 'د(س) = √س'], ['1/x', 'د(س) = ١/س'], ['sin', 'د(س) = جا س']], fnName, function (v) { fnName = v; update(); }));
        container.appendChild(bar);
        bar.appendChild(slider('عدد الأقراص ن', 1, 30, 1, n, function (v) { n = v; update(); }));
        bar.appendChild(slider('بداية الفترة أ', 0.2, 3, 0.1, a, function (v) { a = Math.min(v, b - 0.2); update(); }));
        bar.appendChild(slider('نهاية الفترة ب', 0.5, 4, 0.1, b, function (v) { b = Math.max(v, a + 0.2); update(); }));
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                var lines = [
                    'ندوّر المنطقة تحت المنحنى حول المحور، فتتكوّن مجسّم دوراني.',
                    'نقسّمه إلى ' + Math.round(n) + ' قرصًا: الحجم التقريبي يساوي ' + fmt(approx()) + '.',
                ];
                if (FNS[fnName].exact) lines.push('كلما زاد عدد الأقراص اقترب الناتج من الحجم الحقيقي ' + fmt(FNS[fnName].exact(a, b)) + '.');
                return lines;
            },
        };
    };

    /* 3) المتجهات في الفضاء — a vector and its three projections. */
    KINDS['vector-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var x = p.x != null ? p.x : 2;
        var y = p.y != null ? p.y : 3;
        var z = p.z != null ? p.z : 2;

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 12, yaw: 0.8, pitch: 0.4 });

        function build() {
            scene.clear();
            scene.camera.target = [x / 2, y / 2, z / 2];
            // Axes.
            scene.line([-5, 0, 0], [5, 0, 0], '#cbd5e1', 1.4);
            scene.line([0, -5, 0], [0, 5, 0], '#cbd5e1', 1.4);
            scene.line([0, 0, -5], [0, 0, 5], '#cbd5e1', 1.4);
            // The box that shows the three components.
            scene.line([0, 0, 0], [x, 0, 0], '#94a3b8', 1.2);
            scene.line([x, 0, 0], [x, y, 0], '#94a3b8', 1.2);
            scene.line([x, y, 0], [x, y, z], '#94a3b8', 1.2);
            scene.line([0, 0, 0], [0, y, 0], '#e2e8f0', 1);
            scene.line([0, y, 0], [x, y, 0], '#e2e8f0', 1);
            scene.line([0, 0, 0], [0, 0, z], '#e2e8f0', 1);
            scene.line([0, 0, z], [x, y, z], '#e2e8f0', 1);
            // The vector itself.
            scene.line([0, 0, 0], [x, y, z], '#dc2626', 3);
        }

        function mag() { return Math.hypot(x, y, z); }

        function update() {
            build();
            scene.draw();
            var m = mag() || 1;
            read.innerHTML =
                '<code>ع⃗ = ' + fmt(x) + ' س̂ + ' + fmt(y) + ' ص̂ + ' + fmt(z) + ' ع̂</code>' +
                '<code>|ع⃗| = ' + fmt(mag()) + '</code>' +
                '<code>متجه الوحدة = (' + fmt(x / m) + ' ، ' + fmt(y / m) + ' ، ' + fmt(z / m) + ')</code>' +
                '<code>جتا الزوايا = ' + fmt(x / m) + ' ، ' + fmt(y / m) + ' ، ' + fmt(z / m) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'vector-3d', x: x, y: y, z: z, magnitude: mag() });
        }

        container.appendChild(bar);
        bar.appendChild(slider('المركّبة س', -4, 4, 0.5, x, function (v) { x = v; update(); }));
        bar.appendChild(slider('المركّبة ص', -4, 4, 0.5, y, function (v) { y = v; update(); }));
        bar.appendChild(slider('المركّبة ع', -4, 4, 0.5, z, function (v) { z = v; update(); }));
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'المتجه ع⃗ في الفضاء مركّباته ' + fmt(x) + ' و' + fmt(y) + ' و' + fmt(z) + '.',
                    'طوله يساوي الجذر التربيعي لمجموع مربّعات المركّبات، أي ' + fmt(mag()) + '.',
                    'الصندوق المرسوم يبيّن المساقط الثلاثة على المحاور.',
                ];
            },
        };
    };

    /* 4) السطوح — z = f(x, y) as a shaded surface. */
    KINDS['surface-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var fnName = p.fn || 'bowl';
        var range = p.range != null ? p.range : 2;

        var FNS = {
            bowl: { f: function (x, y) { return x * x + y * y; }, label: 'ع = س² + ص²' },
            saddle: { f: function (x, y) { return x * x - y * y; }, label: 'ع = س² − ص²' },
            plane: { f: function (x, y) { return x + y; }, label: 'ع = س + ص' },
            ripple: { f: function (x, y) { return Math.sin(x) * Math.cos(y) * 1.5; }, label: 'ع = جا س · جتا ص' },
        };

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 11, yaw: 0.9, pitch: 0.5, grid: false });

        function build() {
            scene.clear();
            scene.add(surface(FNS[fnName].f, range, 24, BLUE));
            scene.camera.target = [0, 0, 0];
            scene.camera.dist = Math.max(8, range * 5);
        }

        function update() {
            build();
            scene.draw();
            read.innerHTML =
                '<code>' + FNS[fnName].label + '</code>' +
                '<code>مجال الرسم: −' + fmt(range) + ' ≤ س ، ص ≤ ' + fmt(range) + '</code>' +
                '<code>كل مقطع أفقي (ع = ثابت) هو خط كنتوري.</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'surface-3d', fn: fnName, range: range });
        }

        container.appendChild(pick('السطح', [['bowl', 'ع = س² + ص²'], ['saddle', 'ع = س² − ص²'], ['plane', 'ع = س + ص'], ['ripple', 'ع = جا س · جتا ص']], fnName, function (v) { fnName = v; update(); }));
        container.appendChild(bar);
        bar.appendChild(slider('مدى الرسم', 1, 4, 0.5, range, function (v) { range = v; update(); }));
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'هذا سطح في الفضاء معادلته ' + FNS[fnName].label + '.',
                    'نرسمه على شبكة من النقاط ونصلها فتصبح سطحًا.',
                    'كل مقطع أفقي من السطح يمثّل خطًا كنتوريًا عند قيمة ثابتة للارتفاع.',
                ];
            },
        };
    };

    /* 5) الأرض والشمس — the tilted axis is what makes the seasons. */
    KINDS['earth-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var tilt = p.tilt != null ? p.tilt : 23.5;
        var spin = p.spin != null ? p.spin : 0;

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 13, yaw: 0.7, pitch: 0.35 });

        function place(m, dx, dy, dz) {
            m.v = m.v.map(function (q) { return [q[0] + dx, q[1] + dy, q[2] + dz]; });
            return m;
        }

        function build() {
            scene.clear();
            var R = 2.2, t = tilt * Math.PI / 180;
            var earth = sphere(R, 26); earth.color = BLUE; scene.add(earth);

            // The rotation axis, tilted from the vertical: the seasons come from
            // this tilt, not from the distance to the sun.
            var ax = [Math.sin(t), Math.cos(t), 0];
            scene.line([-ax[0] * R * 1.5, -ax[1] * R * 1.5, 0], [ax[0] * R * 1.5, ax[1] * R * 1.5, 0], '#f59e0b', 2);

            // Equator (perpendicular to the axis) and the rotating meridian.
            var prev = null;
            for (var i = 0; i <= 48; i++) {
                var a = i / 48 * Math.PI * 2;
                var pt = [R * Math.cos(a) * Math.cos(t), -R * Math.cos(a) * Math.sin(t), R * Math.sin(a)];
                if (prev) scene.line(prev, pt, '#e2e8f0', 1);
                prev = pt;
            }
            var m0 = null;
            for (var j = 0; j <= 48; j++) {
                var ang = j / 48 * Math.PI * 2 + spin;
                var mer = [R * Math.cos(ang) * Math.cos(t), R * Math.cos(ang) * Math.sin(t), R * Math.sin(ang)];
                if (m0) scene.line(m0, mer, '#0ea5e9', 1);
                m0 = mer;
            }

            // Sunlight: parallel rays coming from the right.
            for (var k = -2; k <= 2; k++) {
                scene.line([7, k * 1.1, 0], [R * 1.05, k * 1.1, 0], '#fbbf24', 1);
            }
            var sun = place(sphere(1.1, 20), 8.5, 0, 0); sun.color = [245, 158, 11]; scene.add(sun);

            scene.camera.target = [0, 0, 0];
            scene.camera.dist = 15;
        }

        function update() {
            build();
            scene.draw();
            read.innerHTML =
                '<code>ميل المحور = ' + fmt(tilt, 1) + '°</code>' +
                '<code>الدوران حول المحور ينتج الليل والنهار.</code>' +
                '<code>ميل المحور ينتج الفصول الأربعة.</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'earth-3d', tilt: tilt, spin: spin });
        }

        container.appendChild(bar);
        bar.appendChild(slider('ميل المحور (درجة)', 0, 45, 1, tilt, function (v) { tilt = v; update(); }));
        bar.appendChild(slider('زاوية الدوران', 0, 6.28, 0.1, spin, function (v) { spin = v; update(); }));
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'الأرض كرة تدور حول محور مائل بزاوية ' + fmt(tilt, 1) + ' درجة.',
                    'دورانها حول نفسها ينتج الليل والنهار، وميل المحور ينتج الفصول الأربعة.',
                    'أشعة الشمس متوازية؛ النصف المواجه لها نهار، والنصف الآخر ليل.',
                ];
            },
        };
    };

    /* 5b) الذرة — a nucleus with the electron shells filled in order. */
    KINDS['atom-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var Z = p.z != null ? p.z : 6;   // atomic number = electrons to place

        var SHELLS = [2, 8, 18, 32, 32, 18, 8];   // capacity per shell
        var NAMES = ['K', 'L', 'M', 'N', 'O', 'P', 'Q'];

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 12, yaw: 0.8, pitch: 0.35 });

        function place(m, x, y, z) { m.v = m.v.map(function (q) { return [q[0] + x, q[1] + y, q[2] + z]; }); return m; }

        function fill() {
            var left = Math.max(0, Math.round(Z)), out = [];
            for (var i = 0; i < SHELLS.length && left > 0; i++) {
                var take = Math.min(SHELLS[i], left);
                out.push(take); left -= take;
            }
            return out;
        }

        function build() {
            scene.clear();
            var nuc = sphere(0.75, 18); nuc.color = [225, 29, 72]; scene.add(nuc);
            var counts = fill();
            counts.forEach(function (n, i) {
                var r = 1.5 + i * 1.15;
                // The shell itself.
                var prev = null;
                for (var k = 0; k <= 40; k++) {
                    var a = k / 40 * Math.PI * 2;
                    var pt = [r * Math.cos(a), r * Math.sin(a) * 0.35, r * Math.sin(a)];
                    if (prev) scene.line(prev, pt, '#cbd5e1', 1);
                    prev = pt;
                }
                for (var e = 0; e < n; e++) {
                    var t = (e / n) * Math.PI * 2 + i;
                    var el = place(sphere(0.16, 10), r * Math.cos(t), r * Math.sin(t) * 0.35, r * Math.sin(t));
                    el.color = [37, 99, 235];
                    scene.add(el);
                }
            });
            scene.camera.target = [0, 0, 0];
            scene.camera.dist = Math.max(8, 4 + counts.length * 2.4);
        }

        function update() {
            build();
            scene.draw();
            var counts = fill();
            var lines = '<code>العدد الذري = ' + Math.round(Z) + ' (عدد الإلكترونات)</code>';
            counts.forEach(function (n, i) { lines += '<code>المستوى ' + NAMES[i] + ': ' + n + ' إلكترون</code>'; });
            read.innerHTML = lines;
            if (opts && opts.onChange) opts.onChange({ kind: 'atom-3d', z: Z, shells: counts });
        }

        container.appendChild(bar);
        bar.appendChild(slider('العدد الذري', 1, 20, 1, Z, function (v) { Z = v; update(); }));
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                var counts = fill();
                return [
                    'هذه ذرة عددها الذري ' + Math.round(Z) + '.',
                    'في المركز النواة (بروتونات ونيوترونات)، وتدور حولها الإلكترونات في مستويات.',
                    'عدد الإلكترونات يساوي العدد الذري، وتتوزع على المستويات: ' + counts.join(' ، ') + '.',
                ];
            },
        };
    };

    /* 6) الجزيء — ball-and-stick model of a simple molecule. */
    KINDS['molecule-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var name = p.molecule || 'water';

        var MOLS = {
            water: { label: 'جزيء الماء H₂O', center: 'O', atoms: [['O', 0, 0, 0, 0.9], ['H', 1.1, 0.7, 0, 0.55], ['H', -1.1, 0.7, 0, 0.55]], bonds: [[0, 1], [0, 2]] },
            co2: { label: 'جزيء ثاني أكسيد الكربون CO₂', center: 'C', atoms: [['C', 0, 0, 0, 0.8], ['O', 1.6, 0, 0, 0.85], ['O', -1.6, 0, 0, 0.85]], bonds: [[0, 1], [0, 2]] },
            methane: { label: 'جزيء الميثان CH₄', center: 'C', atoms: [['C', 0, 0, 0, 0.8], ['H', 1, 1, 1, 0.5], ['H', -1, -1, 1, 0.5], ['H', -1, 1, -1, 0.5], ['H', 1, -1, -1, 0.5]], bonds: [[0, 1], [0, 2], [0, 3], [0, 4]] },
            ammonia: { label: 'جزيء الأمونيا NH₃', center: 'N', atoms: [['N', 0, 0.4, 0, 0.8], ['H', 1, -0.5, 0, 0.5], ['H', -0.5, -0.5, 0.9, 0.5], ['H', -0.5, -0.5, -0.9, 0.5]], bonds: [[0, 1], [0, 2], [0, 3]] },
        };
        var COLORS = { O: [225, 29, 72], H: [148, 163, 184], C: [51, 65, 85], N: [37, 99, 235] };

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 9, yaw: 0.8, pitch: 0.4 });

        function build() {
            scene.clear();
            var mol = MOLS[name];
            mol.bonds.forEach(function (b) {
                var a = mol.atoms[b[0]], c = mol.atoms[b[1]];
                scene.line([a[1], a[2], a[3]], [c[1], c[2], c[3]], '#94a3b8', 5);
            });
            mol.atoms.forEach(function (at) {
                var s = sphere(at[4], 20);
                s.v = s.v.map(function (q) { return [q[0] + at[1], q[1] + at[2], q[2] + at[3]]; });
                s.color = COLORS[at[0]] || SLATE;
                scene.add(s);
            });
            scene.camera.target = [0, 0, 0];
            scene.camera.dist = 8;
        }

        function update() {
            build();
            scene.draw();
            read.innerHTML =
                '<code>' + MOLS[name].label + '</code>' +
                '<code>الذرات: ' + MOLS[name].atoms.map(function (a) { return a[0]; }).join(' ، ') + '</code>' +
                '<code>الروابط: ' + MOLS[name].bonds.length + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'molecule-3d', molecule: name });
        }

        container.appendChild(pick('الجزيء', [['water', 'ماء H₂O'], ['co2', 'ثاني أكسيد الكربون CO₂'], ['methane', 'ميثان CH₄'], ['ammonia', 'أمونيا NH₃']], name, function (v) { name = v; update(); }));
        container.appendChild(bar);
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'هذا نموذج ' + MOLS[name].label + ' بالكرات والعصي.',
                    'تمثّل الكرات الذرات، وتمثّل العصي الروابط بينها.',
                    'اسحب النموذج لترى شكله في الفضاء من كل الجهات.',
                ];
            },
        };
    };

    /* 7) الخلية — a cell with its nucleus and organelles; plant adds a wall. */
    KINDS['cell-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var plant = p.plant !== false;

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 11, yaw: 0.8, pitch: 0.4 });

        function put(m, x, y, z) { m.v = m.v.map(function (q) { return [q[0] + x, q[1] + y, q[2] + z]; }); return m; }

        function build() {
            scene.clear();
            var R = 2.4;
            if (plant) {
                var wall = box(R * 2.1, R * 2.1, R * 2.1); wall.color = [13, 148, 136]; scene.add(wall);
            }
            var membrane = sphere(R, 26); membrane.color = [37, 99, 235]; scene.add(membrane);
            var nucleus = put(sphere(0.95, 20), 0.7, 0.4, 0.3); nucleus.color = [139, 92, 246]; scene.add(nucleus);
            var vac = put(sphere(0.7, 18), -0.9, -0.6, -0.4); vac.color = [14, 165, 233]; scene.add(vac);
            var mito = put(sphere(0.4, 14), -0.8, 0.8, 0.6); mito.color = [225, 29, 72]; scene.add(mito);
            var chloro = put(sphere(0.4, 14), 0.9, -0.8, -0.7); chloro.color = [22, 163, 74]; scene.add(chloro);
            scene.camera.target = [0, 0, 0];
            scene.camera.dist = plant ? 11 : 9;
        }

        function update() {
            build();
            scene.draw();
            read.innerHTML =
                '<code>نوع الخلية: ' + (plant ? 'خلية نباتية' : 'خلية حيوانية') + '</code>' +
                '<code>النواة تنظم نشاط الخلية.</code>' +
                (plant ? '<code>الجدار الخلوي يمنح الخلية النباتية شكلًا ثابتًا.</code>' : '<code>لا يوجد جدار خلوي ولا بلاستيدات في الخلية الحيوانية.</code>');
            if (opts && opts.onChange) opts.onChange({ kind: 'cell-3d', plant: plant });
        }

        container.appendChild(pick('نوع الخلية', [[true, 'نباتية'], [false, 'حيوانية']], plant, function (v) { plant = (v === true || v === 'true'); update(); }));
        container.appendChild(bar);
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                var lines = ['هذه ' + (plant ? 'خلية نباتية' : 'خلية حيوانية') + ' مجسّمة.']; 
                lines.push('في الوسط النواة، وهي مركز التحكم في الخلية.');
                lines.push(plant ? 'تحيط بها بلاستيدات خضراء وجدار خلوي.' : 'تحيط بها أغشية وعضيّات مختلفة.');
                return lines;
            },
        };
    };

    /* 8) المنشور والضوء — a triangular prism splitting white light. */
    KINDS['prism-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var angle = p.angle != null ? p.angle : 60;

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 13, yaw: 0.9, pitch: 0.35 });
        var RAINBOW = ['#ef4444', '#f97316', '#facc15', '#22c55e', '#3b82f6', '#8b5cf6'];

        function build() {
            scene.clear();
            var pr = prism(3, 1.6, 4.5); pr.color = [203, 213, 225]; scene.add(pr);
            // The incident white ray, then one dispersed ray per spectral colour.
            scene.line([-8, 0.6, 0], [-1.2, 0.2, 0], '#f8fafc', 3);
            RAINBOW.forEach(function (col, i) {
                var spread = (i - (RAINBOW.length - 1) / 2) * 0.55;
                scene.line([1.2, 0.2, 0], [8, spread, 0], col, 2);
            });
            scene.camera.target = [0, 0, 0];
            scene.camera.dist = 14;
        }

        function update() {
            build();
            scene.draw();
            read.innerHTML =
                '<code>زاوية المنشور = ' + fmt(angle, 0) + '°</code>' +
                '<code>الضوء الأبيض ينكسر ويتحلل إلى ألوان الطيف.</code>' +
                '<code>كل لون له معامل انكسار مختلف.</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'prism-3d', angle: angle });
        }

        container.appendChild(bar);
        bar.appendChild(slider('زاوية المنشور', 30, 90, 5, angle, function (v) { angle = v; update(); }));
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'يسقط شعاع ضوء أبيض على منشور ثلاثي.',
                    'ينكسر الضوء مرتين عند دخوله وخروجه، فيتحلل إلى ألوان الطيف.',
                    'يظهر الطيف بالترتيب: أحمر، برتقالي، أصفر، أخضر، أزرق، بنفسجي.',
                ];
            },
        };
    };

    /* 9) أجزاء النبات — the plant's parts, each labelled. */
    KINDS['plant-3d'] = function (container, spec, opts) {
        var p = spec.params || {};
        var show = p.show || 'all';

        var read = readout();
        var bar = controls();
        var scene = new Scene(container, { height: 340, dist: 14, yaw: 0.6, pitch: 0.3 });

        function put(m, x, y, z) { m.v = m.v.map(function (q) { return [q[0] + x, q[1] + y, q[2] + z]; }); return m; }

        function build() {
            scene.clear();
            var showRoots = show === 'all' || show === 'roots';
            var showStem = show === 'all' || show === 'stem';
            var showLeaves = show === 'all' || show === 'leaves';
            var showFlower = show === 'all' || show === 'flower';

            if (showRoots) {
                [-0.6, 0, 0.6].forEach(function (x) {
                    var r = put(cylinder(0.12, 2.2, 12), x, -2.2, 0); r.color = [120, 53, 15]; scene.add(r);
                });
            }
            if (showStem) {
                var s = put(cylinder(0.28, 4.4, 16), 0, 0, 0); s.color = [22, 163, 74]; scene.add(s);
            }
            if (showLeaves) {
                [[1.1, 1.2], [-1.1, 0.2], [1.1, -0.8]].forEach(function (l) {
                    var lf = put(box(2, 0.12, 1.1), l[0], l[1], 0); lf.color = [34, 197, 94]; scene.add(lf);
                });
            }
            if (showFlower) {
                var f = put(sphere(0.7, 18), 0, 2.6, 0); f.color = [225, 29, 72]; scene.add(f);
            }
            scene.camera.target = [0, 0, 0];
            scene.camera.dist = 13;
        }

        function update() {
            build();
            scene.draw();
            var names = { all: 'كل الأجزاء', roots: 'الجذر', stem: 'الساق', leaves: 'الأوراق', flower: 'الزهرة' };
            read.innerHTML =
                '<code>الجزء المعروض: ' + names[show] + '</code>' +
                '<code>الجذر يثبّت النبات ويمتص الماء والأملاح.</code>' +
                '<code>الساق ينقل الماء، والأوراق تصنع الغذاء.</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'plant-3d', show: show });
        }

        container.appendChild(pick('الجزء', [['all', 'كل الأجزاء'], ['roots', 'الجذر'], ['stem', 'الساق'], ['leaves', 'الأوراق'], ['flower', 'الزهرة']], show, function (v) { show = v; update(); }));
        container.appendChild(bar);
        update();
        container.appendChild(read);

        return {
            destroy: function () { scene.destroy(); },
            narrate: function () {
                return [
                    'هذا نبات مجسّم بأجزائه الرئيسة.',
                    'الجذر يثبّت النبات في التربة ويمتص الماء والأملاح المعدنية.',
                    'الساق ينقل الماء إلى الأوراق، والأوراق تصنع الغذاء بالبناء الضوئي، والزهرة عضو التكاثر.',
                ];
            },
        };
    };

    // ------------------------------------------------------------------ public
    window.Interactive3D = {
        kinds: Object.keys(KINDS),
        register: function (Interactive) {
            if (!Interactive || !Interactive.register) return;
            Object.keys(KINDS).forEach(function (k) { Interactive.register(k, KINDS[k]); });
        },
    };
    if (window.Interactive) window.Interactive3D.register(window.Interactive);
})();
