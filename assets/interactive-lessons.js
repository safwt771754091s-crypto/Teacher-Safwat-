/* محرّك نماذج الدروس — Interactive models for the primary and language books.
 *
 * Companion to interactive.js and interactive3d.js. The maths book is served by
 * those two; this file covers the lessons the platform actually authored —
 * the Grade 1 subjects (حروف، حركات، مد، الكائنات الحية، الحواس، الوضوء، أركان
 * الإسلام) and the English course (the alphabet, numbers, telling the time,
 * the family, the present simple …).
 *
 * These models are built from the DOM rather than a canvas. Arabic has to shape
 * and read right-to-left, and the browser already does that correctly for text
 * nodes; drawing Arabic letter forms by hand in a canvas would be wrong on
 * every device. The DOM also makes every model usable with a screen reader and
 * with the platform's own `window.speak` narration.
 *
 * Interaction is tap-to-select then tap-to-place rather than HTML5 drag and
 * drop, because drag events do not fire reliably on the touch screens this
 * platform targets.
 */
(function () {
    'use strict';

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

    function shuffle(a) {
        var r = a.slice();
        for (var i = r.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = r[i]; r[i] = r[j]; r[j] = t;
        }
        return r;
    }

    function readout() { return el('div', 'im-readout'); }

    function say(text) { if (window.speak) window.speak(text); }

    function speakButton(text) {
        var b = el('button', 'btn small', '🔊 اسمع');
        b.onclick = function () { say(typeof text === 'function' ? text() : text); };
        return b;
    }

    var KINDS = {};

    /* 1) بطاقة حرف — a letter, its sound and an example word. */
    KINDS['letter-card'] = function (container, spec, opts) {
        var p = spec.params || {};
        var letter = p.letter || 'أ';
        var sound = p.sound || letter;
        var word = p.word || '';
        var lang = p.lang || 'ar';
        var all = p.letters || [];

        var card = el('div', 'im-letter');
        card.setAttribute('lang', lang);
        card.appendChild(el('div', 'im-letter-glyph', letter));
        var soundLine = el('div', 'im-letter-sound', sound);
        card.appendChild(soundLine);
        if (word) card.appendChild(el('div', 'im-letter-word', word));
        container.appendChild(card);

        var bar = el('div', 'im-controls');
        bar.appendChild(speakButton(function () { return sound + (word ? ' ، ' + word : ''); }));
        if (all.length) {
            var next = el('button', 'btn small', 'التالي ➜');
            var i = all.indexOf(letter);
            next.onclick = function () {
                i = (i + 1) % all.length;
                letter = all[i];
                card.querySelector('.im-letter-glyph').textContent = letter;
                soundLine.textContent = letter;
                say(letter);
            };
            bar.appendChild(next);
        }
        container.appendChild(bar);

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () {
                var lines = ['هذا حرف ' + letter + '.'];
                if (sound && sound !== letter) lines.push('يُقرأ: ' + sound + '.');
                if (word) lines.push('مثال: ' + word + '.');
                return lines;
            },
        };
    };

    /* 2) الساعة — drag the hands, read the time in words. */
    KINDS['clock'] = function (container, spec, opts) {
        var p = spec.params || {};
        var hour = p.hour != null ? p.hour : 3;
        var minute = p.minute != null ? p.minute : 0;

        var canvas = el('canvas', 'im-canvas');
        canvas.style.width = '100%';
        canvas.style.height = '260px';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var DPR = Math.min(window.devicePixelRatio || 1, 2);

        var read = readout();
        var bar = el('div', 'im-controls');

        function arabicTime(h, m) {
            var names = ['الثانية عشرة', 'الواحدة', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة',
                'السابعة', 'الثامنة', 'التاسعة', 'العاشرة', 'الحادية عشرة'];
            var hh = h % 12;
            var hourName = names[hh];
            if (m === 0) return hourName + ' تمامًا';
            if (m === 15) return hourName + ' والربع';
            if (m === 30) return hourName + ' والنصف';
            if (m === 45) return hourName + ' إلا ربعًا';
            return hourName + ' و' + m + ' دقيقة';
        }

        function draw() {
            var w = container.clientWidth || 320, h = 260;
            canvas.width = Math.round(w * DPR);
            canvas.height = Math.round(h * DPR);
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
            ctx.clearRect(0, 0, w, h);
            var cx = w / 2, cy = h / 2, R = Math.min(w, h) / 2 - 16;

            ctx.fillStyle = '#fff';
            ctx.strokeStyle = '#0f766e';
            ctx.lineWidth = 3;
            ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.fill(); ctx.stroke();

            // Hour ticks, with the Arabic numerals 1..12 around the face.
            for (var i = 0; i < 12; i++) {
                var a = (i / 12) * Math.PI * 2 - Math.PI / 2;
                var x1 = cx + Math.cos(a) * (R - 8), y1 = cy + Math.sin(a) * (R - 8);
                var x2 = cx + Math.cos(a) * R, y2 = cy + Math.sin(a) * R;
                ctx.strokeStyle = '#94a3b8'; ctx.lineWidth = 2;
                ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
                ctx.fillStyle = '#1f2937';
                ctx.font = 'bold 15px system-ui';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillText(String(i === 0 ? 12 : i), cx + Math.cos(a) * (R - 26), cy + Math.sin(a) * (R - 26));
            }

            function hand(angle, len, width, color) {
                ctx.strokeStyle = color; ctx.lineWidth = width; ctx.lineCap = 'round';
                ctx.beginPath(); ctx.moveTo(cx, cy);
                ctx.lineTo(cx + Math.cos(angle) * len, cy + Math.sin(angle) * len);
                ctx.stroke();
            }
            var mA = (minute / 60) * Math.PI * 2 - Math.PI / 2;
            var hA = ((hour % 12) / 12 + minute / 720) * Math.PI * 2 - Math.PI / 2;
            hand(hA, R * 0.5, 6, '#1f2937');
            hand(mA, R * 0.76, 4, '#b45309');
            ctx.fillStyle = '#0f766e';
            ctx.beginPath(); ctx.arc(cx, cy, 6, 0, Math.PI * 2); ctx.fill();
        }

        // Dragging anywhere sets the nearest of the twelve hours.
        var drag = false;
        function setFrom(ev) {
            var r = canvas.getBoundingClientRect();
            var pt = ev.touches ? ev.touches[0] : ev;
            var dx = pt.clientX - r.left - r.width / 2;
            var dy = pt.clientY - r.top - r.height / 2;
            var a = Math.atan2(dy, dx) + Math.PI / 2;
            if (a < 0) a += Math.PI * 2;
            hour = Math.round((a / (Math.PI * 2)) * 12) % 12;
            if (hour === 0) hour = 12;
            minute = 0;
            update();
        }
        canvas.addEventListener('mousedown', function (e) { drag = true; setFrom(e); });
        canvas.addEventListener('mousemove', function (e) { if (drag) setFrom(e); });
        window.addEventListener('mouseup', function () { drag = false; });
        canvas.addEventListener('touchstart', function (e) { setFrom(e); if (e.cancelable) e.preventDefault(); }, { passive: false });
        canvas.addEventListener('touchmove', function (e) { setFrom(e); if (e.cancelable) e.preventDefault(); }, { passive: false });

        function update() {
            draw();
            read.innerHTML =
                '<code>' + String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0') + '</code>' +
                '<code>' + arabicTime(hour, minute) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'clock', hour: hour, minute: minute });
        }

        var hS = el('input'); hS.type = 'range'; hS.min = 1; hS.max = 12; hS.value = hour;
        hS.oninput = function () { hour = parseInt(hS.value, 10); update(); };
        var mS = el('input'); mS.type = 'range'; mS.min = 0; mS.max = 55; mS.step = 5; mS.value = minute;
        mS.oninput = function () { minute = parseInt(mS.value, 10); update(); };
        var hl = el('label', 'im-ctrl'); hl.appendChild(el('span', 'im-ctrl-label', 'الساعة')); hl.appendChild(hS);
        var ml = el('label', 'im-ctrl'); ml.appendChild(el('span', 'im-ctrl-label', 'الدقائق')); ml.appendChild(mS);
        bar.appendChild(hl); bar.appendChild(ml);
        container.appendChild(bar);
        container.appendChild(read);
        update();
        var ro = window.ResizeObserver ? new ResizeObserver(draw) : null;
        if (ro) ro.observe(container); else window.addEventListener('resize', draw);

        return {
            destroy: function () { if (ro) ro.disconnect(); else window.removeEventListener('resize', draw); container.innerHTML = ''; },
            narrate: function () { return ['الساعة تشير إلى ' + arabicTime(hour, minute) + '.', 'اسحب العقرب لضبط الوقت ثم اسمع كيف يُقرأ.']; },
        };
    };

    /* 3) العدّ — tap the objects and count them out loud. */
    KINDS['counting'] = function (container, spec, opts) {
        var p = spec.params || {};
        var n = p.n != null ? p.n : 5;
        var icon = p.icon || '🍎';

        var grid = el('div', 'im-count-grid');
        var read = readout();
        var counted = 0;

        function render() {
            grid.innerHTML = '';
            for (var i = 0; i < n; i++) {
                var cell = el('button', 'im-count-cell', icon);
                cell.setAttribute('aria-label', 'عنصر ' + (i + 1));
                if (i < counted) cell.classList.add('on');
                cell.onclick = (function (k) {
                    return function () {
                        counted = (counted === k + 1) ? k : k + 1;
                        paint();
                    };
                })(i);
                grid.appendChild(cell);
            }
            paint();
        }
        function paint() {
            Array.prototype.forEach.call(grid.children, function (c, i) {
                c.classList.toggle('on', i < counted);
            });
            read.innerHTML = '<code>عدد العناصر المعدودة: ' + counted + ' من ' + n + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'counting', n: n, counted: counted });
        }

        container.appendChild(grid);
        var bar = el('div', 'im-controls');
        bar.appendChild(speakButton(function () { return 'العدد ' + n; }));
        container.appendChild(bar);
        container.appendChild(read);
        render();

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () { return ['نعدّ العناصر واحدًا واحدًا.', 'عددها ' + n + '.', 'اضغط على العناصر لتعدّها معي.']; },
        };
    };

    /* 4) الترتيب — put the steps in the right order. */
    KINDS['sequence'] = function (container, spec, opts) {
        var p = spec.params || {};
        var steps = (p.steps || []).slice();
        var prompt = p.prompt || 'رتّب الخطوات بالترتيب الصحيح.';
        var shuffled = shuffle(steps);

        var list = el('div', 'im-seq');
        var read = readout();
        var picked = null;

        function paint() {
            list.innerHTML = '';
            shuffled.forEach(function (s, i) {
                var row = el('button', 'im-seq-item', s);
                if (picked === i) row.classList.add('picked');
                row.onclick = function () {
                    if (picked === null) { picked = i; }
                    else if (picked === i) { picked = null; }
                    else {
                        var t = shuffled[picked]; shuffled[picked] = shuffled[i]; shuffled[i] = t;
                        picked = null;
                    }
                    paint();
                };
                list.appendChild(row);
            });
            var right = shuffled.join('|') === steps.join('|');
            read.innerHTML = '<code>' + (right ? '✅ ترتيب صحيح!' : 'اضغط خطوتين لتبديلهما.') + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'sequence', order: shuffled.slice(), correct: right });
        }

        container.appendChild(el('p', 'im-prompt', prompt));
        container.appendChild(list);
        var bar = el('div', 'im-controls');
        var reset = el('button', 'btn small', '↺ إعادة');
        reset.onclick = function () { shuffled = shuffle(steps); picked = null; paint(); };
        bar.appendChild(reset);
        bar.appendChild(speakButton(function () { return steps.join('، ثم '); }));
        container.appendChild(bar);
        container.appendChild(read);
        paint();

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () { return ['الترتيب الصحيح هو: ' + steps.join('، ثم ') + '.']; },
        };
    };

    /* 5) التصنيف — drop each item into the right bin. */
    KINDS['sort-bins'] = function (container, spec, opts) {
        var p = spec.params || {};
        var bins = p.bins || ['أ', 'ب'];
        var items = (p.items || []).slice();
        var pool = shuffle(items);

        var binsWrap = el('div', 'im-bins');
        var poolWrap = el('div', 'im-pool');
        var read = readout();
        var selected = null;
        var placed = {};   // item label -> bin index

        function paint() {
            poolWrap.innerHTML = '';
            binsWrap.innerHTML = '';
            var remaining = pool.filter(function (it) { return placed[it.label] === undefined; });
            if (!remaining.length) poolWrap.appendChild(el('span', 'muted small', 'تم وضع كل العناصر ✔'));
            remaining.forEach(function (it) {
                var chip = el('button', 'im-chip', it.label);
                if (selected === it.label) chip.classList.add('picked');
                chip.onclick = function () { selected = (selected === it.label ? null : it.label); paint(); };
                poolWrap.appendChild(chip);
            });

            bins.forEach(function (b, bi) {
                var box = el('div', 'im-bin');
                box.appendChild(el('div', 'im-bin-title', b));
                var body = el('div', 'im-bin-body');
                pool.forEach(function (it) {
                    if (placed[it.label] === bi) {
                        var chip = el('span', 'im-chip placed', it.label);
                        body.appendChild(chip);
                    }
                });
                box.appendChild(body);
                box.onclick = function () {
                    if (selected) { placed[selected] = bi; selected = null; paint(); }
                };
                binsWrap.appendChild(box);
            });

            var correct = pool.filter(function (it) { return placed[it.label] !== undefined; })
                .every(function (it) { return bins[placed[it.label]] === it.bin; });
            var done = pool.every(function (it) { return placed[it.label] !== undefined; });
            read.innerHTML = '<code>' + (done ? (correct ? '✅ تصنيف صحيح!' : '❌ راجع التصنيف.') : 'اختر عنصرًا ثم اضغط الصندوق المناسب.') + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'sort-bins', done: done, correct: done && correct });
        }

        container.appendChild(el('p', 'im-prompt', p.prompt || 'صنّف كل عنصر في المكان الصحيح.'));
        container.appendChild(poolWrap);
        container.appendChild(binsWrap);
        var bar = el('div', 'im-controls');
        var reset = el('button', 'btn small', '↺ إعادة');
        reset.onclick = function () { placed = {}; selected = null; pool = shuffle(items); paint(); };
        bar.appendChild(reset);
        container.appendChild(bar);
        container.appendChild(read);
        paint();

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () {
                return ['نصنّف العناصر في مجموعتين: ' + bins.join(' و ') + '.',
                    items.map(function (i) { return i.label + ' ← ' + i.bin; }).join('، ') + '.'];
            },
        };
    };

    /* 6) شجرة العائلة — a labelled family tree. */
    KINDS['family-tree'] = function (container, spec, opts) {
        var p = spec.params || {};
        var nodes = p.nodes || [];

        var tree = el('div', 'im-tree');
        nodes.forEach(function (n) {
            var card = el('div', 'im-tree-node');
            card.appendChild(el('div', 'im-tree-glyph', n.icon || '👤'));
            card.appendChild(el('div', 'im-tree-label', n.label || ''));
            if (n.relation) card.appendChild(el('div', 'im-tree-rel', n.relation));
            card.onclick = function () { say(n.label + (n.relation ? ' ، ' + n.relation : '')); };
            tree.appendChild(card);
        });
        container.appendChild(el('p', 'im-prompt', p.prompt || 'اضغط على أي فرد لتسمع اسمه.'));
        container.appendChild(tree);
        container.appendChild(readout());
        container.lastChild.innerHTML = '<code>' + nodes.map(function (n) { return n.label; }).join(' • ') + '</code>';

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () { return nodes.map(function (n) { return n.label + (n.relation ? ' ' + n.relation : ''); }); },
        };
    };

    /* 7) بناء الجملة — tap the words in the right order. */
    KINDS['sentence-builder'] = function (container, spec, opts) {
        var p = spec.params || {};
        var words = (p.words || []).slice();
        var answer = p.answer || words.join(' ');
        var dir = p.dir || 'ltr';

        var chosen = [];
        var line = el('div', 'im-sentence');
        line.setAttribute('dir', dir);
        var bank = el('div', 'im-wordbank');
        bank.setAttribute('dir', dir);
        var read = readout();

        function paint() {
            line.innerHTML = '';
            if (!chosen.length) line.appendChild(el('span', 'muted small', 'اختر الكلمات بالترتيب…'));
            chosen.forEach(function (w, i) {
                var chip = el('button', 'im-chip placed', w);
                chip.onclick = function () { chosen.splice(i, 1); paint(); };
                line.appendChild(chip);
            });
            bank.innerHTML = '';
            words.forEach(function (w, i) {
                var chip = el('button', 'im-chip', w);
                if (chosen.indexOf(i) !== -1) chip.classList.add('used');
                chip.disabled = chosen.indexOf(i) !== -1;
                chip.onclick = function () { chosen.push(i); paint(); };
                bank.appendChild(chip);
            });
            var built = chosen.map(function (i) { return words[i]; }).join(' ');
            var right = built === answer;
            read.innerHTML = '<code>' + (right ? '✅ أحسنت!' : 'الجملة: ' + (built || '…')) + '</code>';
            if (opts && opts.onChange) opts.onChange({ kind: 'sentence-builder', built: built, correct: right });
        }

        container.appendChild(el('p', 'im-prompt', p.prompt || 'كوّن جملة صحيحة من الكلمات.'));
        container.appendChild(line);
        container.appendChild(bank);
        var bar = el('div', 'im-controls');
        var reset = el('button', 'btn small', '↺ إعادة');
        reset.onclick = function () { chosen = []; paint(); };
        bar.appendChild(reset);
        bar.appendChild(speakButton(function () { return answer; }));
        container.appendChild(bar);
        container.appendChild(read);
        paint();

        return {
            destroy: function () { container.innerHTML = ''; },
            narrate: function () { return ['الجملة الصحيحة: ' + answer + '.']; },
        };
    };

    window.InteractiveLessons = {
        kinds: Object.keys(KINDS),
        register: function (Interactive) {
            if (!Interactive || !Interactive.register) return;
            Object.keys(KINDS).forEach(function (k) { Interactive.register(k, KINDS[k]); });
        },
    };
    if (window.Interactive) window.InteractiveLessons.register(window.Interactive);
})();
