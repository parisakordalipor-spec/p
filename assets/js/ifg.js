/*!
 * Infinite Scroll Gallery for Elementor — frontend script
 * Port of "Infinite Scroll Gallery and Flip Transitions in GSAP" (Codrops / Surya Aditya, MIT)
 * Requires: gsap, Flip, SplitText, Observer (global)
 */
(function (window, document) {
  "use strict";

  var gsap = window.gsap;
  var Flip = window.Flip;
  var SplitText = window.SplitText;
  var Observer = window.Observer;

  // "No conflict": the plugin ships its own GSAP build. If the theme or another plugin had already
  // loaded a different GSAP before ours, put its globals back so we never break its animations.
  // (PHP stores the previous globals in window.__ifgPrev right before loading our copy.)
  var prev = window.__ifgPrev;
  if (prev && typeof prev === "object") {
    ["gsap", "Flip", "SplitText", "Observer"].forEach(function (k) {
      if (prev[k] && window[k] !== prev[k]) window[k] = prev[k];
    });
    try { delete window.__ifgPrev; } catch (e) { window.__ifgPrev = undefined; }
  }

  if (!gsap || !Flip || !SplitText || !Observer) {
    if (window.console) console.warn("[IFG] GSAP, Flip, SplitText and Observer are required.");
    // The endless column cannot run without GSAP: drop the "script is running" flag so the stylesheet shows the
    // plain grid fallback instead of a blank box.
    try {
      Array.prototype.forEach.call(document.querySelectorAll(".ifg"), function (el) { el.classList.remove("ifg--js"); });
    } catch (e) {}
    return;
  }

  gsap.registerPlugin(Flip, SplitText, Observer);

  /* ------------------------------------------------------------------ */
  /* Defaults                                                            */
  /* ------------------------------------------------------------------ */
  var DEFAULTS = {
    overlay: "contained", // contained | fixed
    sensitivity: 1,
    smoothness: 0.75,
    scrubEase: "power3.out",
    invert: false,
    wheel: true,
    touch: true,
    keyboard: true,
    parallax: true,
    parallaxSpeeds: "1.3, 0.8, 1.15, 0.7, 1.25, 0.85",
    autoplay: false,
    autoplaySpeed: 40,
    autoplayPause: true,
    loader: true,
    revealEffect: "fade", // fade | rise | clip | scale
    revealDuration: 1,
    revealStagger: 0.12,
    captionReveal: true,
    captionStagger: 0.025,
    openDuration: 1.2,
    openEase: "power4.inOut",
    closeDuration: 1,
    closeEase: "power3.inOut",
    dimOthers: true,
    textEffect: "chars", // chars | words | lines
    textStagger: 0.01,
    textDelay: 0.8,
    closeOnEsc: true,
    clickAction: "content", // content | link | none
    respectReducedMotion: true,
    pageMode: false,
    scrollMode: "always", // always | click
    cart: null, // { url, strings } — WooCommerce add-to-cart (set by PHP)
    filter: null // { url, action, post, widget, current, strings } — category filter (set by PHP)
  };

  var EFFECTS = {
    fade: { from: { autoAlpha: 0 }, to: { autoAlpha: 1 } },
    rise: { from: { autoAlpha: 0, y: 70 }, to: { autoAlpha: 1, y: 0 } },
    clip: {
      from: { autoAlpha: 1, clipPath: "inset(100% 0% 0% 0%)" },
      to: { autoAlpha: 1, clipPath: "inset(0% 0% 0% 0%)" }
    },
    scale: { from: { autoAlpha: 0, scale: 0.8 }, to: { autoAlpha: 1, scale: 1 } }
  };

  // Arabic / Persian / Hebrew and other joined or shaped scripts (Indic, Thai, Khmer, Myanmar) must not
  // be split into characters, otherwise the letters lose their joining / shaping.
  var RTL_SCRIPT = /[\u0590-\u08FF\u0900-\u0DFF\u0E00-\u0EFF\u1000-\u109F\u1780-\u17FF\uFB1D-\uFDFF\uFE70-\uFEFF]/;

  function toArr(sel, ctx) {
    return gsap.utils.toArray(sel, ctx);
  }

  function num(v, fallback) {
    var n = parseFloat(v);
    return isNaN(n) ? fallback : n;
  }

  function once(fn) {
    var done = false;
    return function () {
      if (done) return;
      done = true;
      return fn.apply(this, arguments);
    };
  }

  // "%s" / "%d" placeholder replacement for the strings PHP sends along
  function fmt(str, val) {
    return String(str || "").replace(/%[sd]/, val);
  }

  /* ------------------------------------------------------------------ */
  /* verticalLoop — animate items along the y-axis in an endless loop   */
  /* ------------------------------------------------------------------ */
  function verticalLoop(items, config) {
    items = gsap.utils.toArray(items);
    config = config || {};

    var tl = gsap.timeline({
      repeat: config.repeat,
      paused: config.paused,
      defaults: { ease: "none" },
      onReverseComplete: function () {
        tl.totalTime(tl.rawTime() + tl.duration() * 100);
      }
    });

    var length = items.length;
    var startY = 0;
    var heights = [];
    var yPercents = [];
    var pixelsPerSecond = (config.speed || 1) * 100;
    var snap = config.snap === false ? function (v) { return v; } : gsap.utils.snap(config.snap || 1);

    gsap.set(items, {
      yPercent: function (i, el) {
        var h = (heights[i] = parseFloat(gsap.getProperty(el, "height", "px")));
        yPercents[i] = snap(
          (parseFloat(gsap.getProperty(el, "y", "px")) / h) * 100 + gsap.getProperty(el, "yPercent")
        );
        return yPercents[i];
      }
    });
    gsap.set(items, { y: 0 });

    var totalHeight =
      items[length - 1].offsetTop +
      (yPercents[length - 1] / 100) * heights[length - 1] -
      startY +
      items[length - 1].offsetHeight * gsap.getProperty(items[length - 1], "scaleY") +
      (parseFloat(config.paddingBottom) || 0);

    for (var i = 0; i < length; i++) {
      var item = items[i];
      var curY = (yPercents[i] / 100) * heights[i];
      var distanceToStart = item.offsetTop + curY - startY;
      var distanceToLoop = distanceToStart + heights[i] * gsap.getProperty(item, "scaleY");

      tl.to(
        item,
        {
          yPercent: snap(((curY - distanceToLoop) / heights[i]) * 100),
          duration: distanceToLoop / pixelsPerSecond
        },
        0
      ).fromTo(
        item,
        { yPercent: snap(((curY - distanceToLoop + totalHeight) / heights[i]) * 100) },
        {
          yPercent: yPercents[i],
          duration: (totalHeight - distanceToLoop) / pixelsPerSecond,
          immediateRender: false
        },
        distanceToLoop / pixelsPerSecond
      );
    }

    tl.progress(1, true).progress(0, true);
    return tl;
  }

  /* ------------------------------------------------------------------ */
  /* Reveal                                                              */
  /* ------------------------------------------------------------------ */
  function Reveal(root, s) {
    this.s = s;
    this.fx = EFFECTS[s.revealEffect] || EFFECTS.fade;
    this.items = new Map();
    this.splits = [];

    var self = this;
    toArr(".ifg__slide", root).forEach(function (slide) {
      var wrapper = slide.querySelector(".ifg__img");
      var caption = slide.querySelector(".ifg__caption");
      var parts = [];
      var stagger = 0;

      if (caption) {
        if (s.captionReveal) {
          var rtl = RTL_SCRIPT.test(caption.textContent);
          var split = SplitText.create(caption, { type: rtl ? "words" : "chars" });
          self.splits.push(split);
          parts = rtl ? split.words : split.chars;
          stagger = rtl ? s.captionStagger * 6 : s.captionStagger;
        } else {
          parts = [caption];
        }
      }

      gsap.set(wrapper, self.fx.from);
      gsap.set(parts, { autoAlpha: 0 });

      self.items.set(slide, { wrapper: wrapper, parts: parts, stagger: stagger });
    });
  }

  Reveal.prototype.toggle = function (changes, immediate) {
    var self = this;
    changes
      .filter(function (c) { return c.visible; })
      .sort(function (a, b) { return a.top - b.top; })
      .forEach(function (c, i) { self.show(c.el, i * self.s.revealStagger, immediate); });

    changes
      .filter(function (c) { return !c.visible; })
      .forEach(function (c) { self.hide(c.el); });
  };

  Reveal.prototype.show = function (slide, delay, immediate) {
    var it = this.items.get(slide);
    if (!it) return;

    if (immediate) {
      gsap.set(it.wrapper, Object.assign({ overwrite: true }, this.fx.to));
      gsap.set(it.parts, { autoAlpha: 1, overwrite: true });
      return;
    }

    gsap.to(it.wrapper, Object.assign({}, this.fx.to, {
      duration: this.s.revealDuration,
      ease: "power2.out",
      delay: delay,
      overwrite: true
    }));

    gsap.to(it.parts, {
      autoAlpha: 1,
      duration: 0.4,
      ease: "none",
      stagger: it.stagger,
      delay: delay + 0.2,
      overwrite: true
    });
  };

  Reveal.prototype.hide = function (slide) {
    var it = this.items.get(slide);
    if (!it) return;
    gsap.set(it.wrapper, Object.assign({ overwrite: true }, this.fx.from));
    gsap.set(it.parts, { autoAlpha: 0, overwrite: true });
  };

  Reveal.prototype.destroy = function () {
    this.items.forEach(function (it) {
      gsap.killTweensOf([it.wrapper].concat(it.parts));
      gsap.set(it.wrapper, { clearProps: "all" });
    });
    this.splits.forEach(function (sp) { sp.revert(); });
    this.items.clear();
  };
  
  /* ------------------------------------------------------------------ */
  /* Slider — endless loop driven by wheel / touch / keys / autoplay     */
  /* ------------------------------------------------------------------ */
  function Slider(root, s, hooks) {
    this.root = root;
    this.s = s;
    this.enabled = hooks.enabled;
    this.onToggle = hooks.onToggle;
    this.gallery = root.querySelector(".ifg__gallery");
    this.slides = toArr(".ifg__slide", root);
    this.hover = false;
    this.focusWithin = false;
    this.userPaused = !!hooks.userPaused; // set by the visible pause button (WCAG 2.2.2)
    this.inView = true;
    this.listeners = [];
    // "click" mode: the page scrolls normally until the visitor clicks the gallery
    this.engaged = s.scrollMode !== "click";

    this.createLoop();
    this.createParallax();
    this.createScrub();
    this.createObserver();
    this.createExtras();
  }

  Slider.prototype.on = function (target, ev, fn, opts) {
    target.addEventListener(ev, fn, opts);
    this.listeners.push([target, ev, fn, opts]);
  };

  Slider.prototype.createLoop = function () {
    var gap = parseFloat(getComputedStyle(this.gallery).rowGap) || 0;

    this.loop = verticalLoop(this.slides, {
      repeat: -1,
      paused: true,
      paddingBottom: gap
    });

    this.wrap = gsap.utils.wrap(0, this.loop.duration());
  };

  Slider.prototype.createParallax = function () {
    var speeds = String(this.s.parallaxSpeeds)
      .split(",")
      .map(function (v) { return parseFloat(v); })
      .filter(function (v) { return !isNaN(v); });
    if (!speeds.length) speeds = [1];

    var on = !!this.s.parallax;
    this.parallax = this.slides.map(function (slide, i) {
      return {
        el: slide,
        factor: on ? speeds[i % speeds.length] - 1 : 0,
        offset: 0,
        visible: false
      };
    });

    this.applyParallax();
  };

  Slider.prototype.applyParallax = function (immediate) {
    var changes = [];
    var rootRect = this.root.getBoundingClientRect();
    var viewH = rootRect.height;

    this.parallax.forEach(function (item) {
      var rect = item.el.getBoundingClientRect();
      var loopTop = rect.top - rootRect.top - item.offset;

      item.offset = item.factor * (loopTop + rect.height);
      if (item.factor !== 0 || item.offset !== 0) gsap.set(item.el, { y: item.offset });

      var top = loopTop + item.offset;
      var visible = top < viewH && top + rect.height > 0;

      if (visible !== item.visible) {
        item.visible = visible;
        changes.push({ el: item.el, visible: visible, top: top });
      }
    });

    if (changes.length && this.onToggle) this.onToggle(changes, immediate);
  };

  Slider.prototype.createScrub = function () {
    var self = this;
    this.playhead = { time: 0 };

    this.scrub = gsap.to(this.playhead, {
      time: 0,
      duration: this.s.smoothness,
      ease: this.s.scrubEase,
      paused: true,
      onUpdate: function () {
        self.loop.time(self.wrap(self.playhead.time));
        self.applyParallax();
      }
    });
  };

  Slider.prototype.createObserver = function () {
    var self = this;
    var types = [];
    if (this.s.wheel) types.push("wheel");
    if (this.s.touch) types.push("touch");

    if (!this.s.touch) this.root.classList.add("ifg--no-touch");
    if (!types.length) return;

    this.observer = Observer.create({
      target: this.root,
      type: types.join(","),
      preventDefault: true,
      // The category bar scrolls sideways by itself on small screens: its gestures must not scroll the gallery
      ignore: ".ifg__filter-list",
      onChange: function (obs) {
        self.scroll(obs);
      }
    });

    if (!this.engaged) this.observer.disable();
    this.syncEngagedClass();
  };

  Slider.prototype.syncEngagedClass = function () {
    this.root.classList.toggle("ifg--engaged", !!this.engaged);
  };

  // Start / stop capturing wheel + touch (only meaningful in "click" scroll mode)
  Slider.prototype.engage = function () {
    if (this.engaged) return;
    this.engaged = true;
    if (this.observer && this.enabled()) this.observer.enable();
    this.syncEngagedClass();
  };

  Slider.prototype.release = function () {
    if (!this.engaged || this.s.scrollMode !== "click") return;
    this.engaged = false;
    if (this.observer) this.observer.disable();
    this.syncEngagedClass();
  };

  Slider.prototype.createExtras = function () {
    var self = this;
    var root = this.root;

    this.on(root, "mouseenter", function () { self.hover = true; });
    this.on(root, "mouseleave", function () { self.hover = false; });

    // Autoplay also holds still while a slide has keyboard focus (only slides: the pause / filter buttons must not freeze it)
    this.on(root, "focusin", function (e) {
      self.focusWithin = !!(e.target && e.target.closest && e.target.closest(".ifg__slide"));
    });
    this.on(root, "focusout", function () { self.focusWithin = false; });

    // Keyboard navigation while the widget is hovered or focused
    if (this.s.keyboard) {
      this.on(document, "keydown", function (e) {
        if (!self.enabled() || !self.engaged || e.defaultPrevented) return;
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        if (!(self.hover || root.contains(document.activeElement))) return;
        var t = e.target;
        if (t && t.closest && t.closest("input, textarea, select, [contenteditable='true']")) return;

        var step = 0;
        if (e.key === "ArrowDown") step = 160;
        else if (e.key === "ArrowUp") step = -160;
        else if (e.key === "PageDown") step = 700;
        else if (e.key === "PageUp") step = -700;
        if (!step) return;

        e.preventDefault();
        self.scroll({ deltaX: 0, deltaY: step });
      });
    }

    if (typeof IntersectionObserver !== "undefined") {
      this.io = new IntersectionObserver(function (entries) {
        self.inView = entries[entries.length - 1].isIntersecting;
        if (!self.inView) self.release();
      });
      this.io.observe(root);
    }

    // Autoplay
    if (this.s.autoplay) {
      this.tick = function (time, dt) {
        if (!self.enabled() || !self.inView || self.userPaused) return;
        if (self.s.autoplayPause && (self.hover || self.focusWithin)) return;
        self.scroll({ deltaX: 0, deltaY: (self.s.autoplaySpeed * dt) / 1000 }, true);
      };
      gsap.ticker.add(this.tick);
    }
  };

  Slider.prototype.scroll = function (e, raw) {
    if (!this.enabled()) return;

    var dx = e.deltaX || 0;
    var dy = e.deltaY || 0;
    var delta = Math.abs(dx) > Math.abs(dy) ? dx : dy;

    if (!raw) {
      delta *= this.s.sensitivity;
      if (this.s.invert) delta *= -1;
    }

    this.scrub.vars.time += delta / 100;
    this.scrub.invalidate().restart();
  };

  Slider.prototype.freeze = function () {
    this.scrub.pause();
    this.scrub.vars.time = this.playhead.time;
    this.scrub.invalidate();
  };

  Slider.prototype.stop = function () {
    if (this.observer) this.observer.disable();
    this.freeze();
  };

  Slider.prototype.start = function () {
    if (this.observer && this.engaged) this.observer.enable();
  };

  Slider.prototype.rebuild = function () {
    var progress = this.loop.progress();

    this.freeze();
    this.loop.kill();
    gsap.set(this.slides, { clearProps: "transform" });

    this.createLoop();
    this.loop.progress(progress, true);

    this.parallax.forEach(function (item) { item.offset = 0; });
    this.applyParallax(true);

    this.playhead.time = this.loop.time();
    this.scrub.vars.time = this.playhead.time;
    this.scrub.invalidate();
  };

  Slider.prototype.destroy = function () {
    if (this.observer) this.observer.kill();
    if (this.tick) gsap.ticker.remove(this.tick);
    if (this.io) this.io.disconnect();
    this.listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
    this.listeners = [];
    if (this.scrub) this.scrub.kill();
    if (this.loop) this.loop.kill();
    gsap.killTweensOf(this.slides);
    gsap.set(this.slides, { clearProps: "transform" });
  };

  /* ------------------------------------------------------------------ */
  /* Transition — image morphs into the content view (GSAP Flip)        */
  /* ------------------------------------------------------------------ */
  function Transition(root, s, hooks) {
    this.root = root;
    this.s = s;
    this.onClose = hooks.onClose;
    this.onClosing = hooks.onClosing; // fires as the close finishes, before focus returns to the slide
    this.beforeOpen = hooks.beforeOpen;

    // Video slides: Flip only ever morphs the poster <img>. The real player (<video> / <iframe>) is mounted on top
    // of it once the content view has finished opening and is removed again before the close animation starts.
    this.video = null;
    this.mountToken = 0;
    this.buy = null;
    this.proof = null;

    this.content = root.querySelector(".ifg__content");
    this.preview = root.querySelector(".ifg__preview");
    this.previewImg = this.preview.querySelector("img");
    this.back = root.querySelector(".ifg__back");
    this.groups = toArr(".ifg__group", root);
    this.slides = toArr(".ifg__slide", root);

    this.activeSlide = null;
    this.tl = null;
    this.split = null;
    this.state = "closed";
    this.preview.dataset.flipId = "preview";
  }

  Transition.prototype.imgScale = function () {
    return num(getComputedStyle(this.root).getPropertyValue("--ifg-img-scale"), 1.2);
  };

  Transition.prototype.open = function (slide, index) {
    var self = this;
    if (this.state !== "closed") return Promise.resolve();
    this.state = "opening";
    this.activeSlide = slide;
    if (this.beforeOpen) this.beforeOpen();

    return this.fillContent(slide, index).then(function () {
      if (self.state !== "opening") return;

      var p = self.parts();
      var wrapper = p.wrapper;
      var s = self.s;
      wrapper.dataset.flipId = "preview";

      var state = Flip.getState(wrapper);

      gsap.set(self.content, { display: "block" });
      self.content.setAttribute("aria-hidden", "false");
      gsap.killTweensOf(wrapper);
      gsap.set(wrapper, { autoAlpha: 0 });

      var activeGroup = self.groups.filter(function (g) { return g.classList.contains("active"); })[0];
      // The price + "Add to cart" block is animated as one piece and never split into letters: its button label
      // changes at runtime ("Adding…", "Added!") and SplitText would put the old text back when it reverts.
      var buy = activeGroup ? activeGroup.querySelector(".ifg__buy") : null;
      self.buy = buy;
      // Rating / sale line: same treatment. Its star overlay and badge would break if split into letters.
      var proof = activeGroup ? activeGroup.querySelector(".ifg__proof") : null;
      self.proof = proof;
      var kids = activeGroup ? Array.prototype.slice.call(activeGroup.children) : [];
      var targets = [self.back].concat(kids.filter(function (k) { return k !== buy && k !== proof; }));
      var text = targets.map(function (t) { return t.textContent; }).join(" ");
      var effect = s.textEffect;
      if (effect === "chars" && RTL_SCRIPT.test(text)) effect = "words";
      self.effect = effect;

      var type = effect === "chars" ? "lines,chars" : effect === "words" ? "lines,words" : "lines";
      // Namespaced class names: generic ".char" / ".word" / ".line" collide with many themes
      self.split = SplitText.create(targets, {
        type: type,
        charsClass: "ifg-char",
        wordsClass: "ifg-word",
        linesClass: "ifg-line"
      });

      self.tl = gsap
        .timeline({
          onComplete: function () {
            // close() may have been called while the text was still animating
            if (self.state !== "opening") return;
            self.state = "open";
            if (self.back) self.back.focus({ preventScroll: true });
          },
          onReverseComplete: function () { self.reset(); }
        });

      if (s.dimOthers) {
        self.tl.to(p.others, { autoAlpha: 0, duration: 0.5, ease: "power2.out" }, 0);
      }
      if (self.isFixed()) {
        self.tl.fromTo(
          self.content,
          { "--ifg-fade": 0 },
          { "--ifg-fade": 1, duration: Math.max(0.2, s.openDuration * 0.6), ease: "power2.out" },
          0
        );
      }
      self.tl
        .to(p.caption, { autoAlpha: 0, duration: 0.3, ease: "power2.out" }, 0)
        .add(
          Flip.from(state, {
            targets: self.preview,
            duration: s.openDuration,
            ease: s.openEase,
            absolute: true,
            // The frame has reached its final size: now (and only now) the poster can be swapped for the real player
            onComplete: function () { self.mountVideo(); }
          }),
          0
        )
        .to(self.previewImg, { scale: 1, duration: s.openDuration, ease: s.openEase }, 0);

      self.split.lines.forEach(function (line, i) {
        if (effect === "lines") {
          self.tl.fromTo(
            line,
            { autoAlpha: 0, y: 26 },
            { autoAlpha: 1, y: 0, duration: 1, ease: "power3.out" },
            s.textDelay + i * 0.12
          );
          return;
        }
        var sel = effect === "words" ? ".ifg-word" : ".ifg-char";
        var stagger = effect === "words" ? s.textStagger * 6 : s.textStagger;
        self.tl.fromTo(
          line.querySelectorAll(sel),
          { autoAlpha: 0 },
          { autoAlpha: 1, duration: 1, ease: "power3.out", stagger: stagger },
          s.textDelay + i * 0.06
        );
      });

      if (buy) {
        self.tl.fromTo(
          buy,
          { autoAlpha: 0, y: 20 },
          { autoAlpha: 1, y: 0, duration: 0.8, ease: "power3.out" },
          s.textDelay + 0.25
        );
      }
      if (proof) {
        self.tl.fromTo(
          proof,
          { autoAlpha: 0, y: 20 },
          { autoAlpha: 1, y: 0, duration: 0.8, ease: "power3.out" },
          s.textDelay + 0.25
        );
      }
    });
  };

  Transition.prototype.close = function () {
    var self = this;
    if (this.state !== "opening" && this.state !== "open") return;

    var wasOpening = this.state === "opening";
    this.state = "closing";

    // A mounted player (iframe / <video>) must be gone BEFORE Flip starts resizing the frame — iframes cannot be
    // scaled smoothly. The poster underneath is the same picture, so a short fade-out hides the swap.
    this.unmountVideo(true, function () {
      if (self.state !== "closing") return;
      if (wasOpening && self.tl && self.tl.progress() < 1) {
        self.tl.reverse(); // still opening: just play the opening animation backwards
      } else if (!self.tl || !self.split) {
        self.reset();
      } else {
        self.closeOpen();
      }
    });
  };

  // Close from the fully open state: the preview morphs back into the thumbnail it came from
  Transition.prototype.closeOpen = function () {
    var self = this;
    var s = this.s;

    var p = this.parts();

    this.tl = gsap.timeline({ onComplete: function () { self.reset(); } });
    this.tl.to(this.split.lines.concat(this.buy ? [this.buy] : [], this.proof ? [this.proof] : []), { autoAlpha: 0, duration: 0.4, stagger: 0.04, ease: "power1.out" }, 0)
      .add(
        Flip.fit(this.preview, p.wrapper, {
          duration: s.closeDuration,
          ease: s.closeEase,
          absolute: true
        }),
        0
      )
      .to(this.previewImg, { scale: this.imgScale(), duration: s.closeDuration, ease: s.closeEase }, 0);

    if (this.isFixed()) {
      this.tl.to(this.content, { "--ifg-fade": 0, duration: Math.max(0.2, s.closeDuration * 0.55), ease: "power2.in" }, 0.1);
    }
    if (s.dimOthers) {
      this.tl.to(p.others, { autoAlpha: 1, duration: 0.5, ease: "power2.out" }, 0.5);
    }
    this.tl.to(p.caption, { autoAlpha: 1, duration: 0.4, ease: "power2.out" }, 0.6);
  };

  Transition.prototype.isFixed = function () {
    return this.root.classList.contains("ifg--overlay-fixed");
  };

  Transition.prototype.fillContent = function (slide, index) {
    var img = slide.querySelector(".ifg__img img");
    // The thumbnail may be a small responsive candidate; the big preview loads the full configured size
    this.previewImg.removeAttribute("srcset");
    this.previewImg.src = img.getAttribute("data-full") || img.currentSrc || img.src;
    this.previewImg.alt = img.alt;

    this.groups.forEach(function (group) {
      group.classList.toggle("active", Number(group.dataset.index) === index);
    });

    // The content view is a modal dialog named after the slide title
    var activeTitle = this.groups
      .filter(function (g) { return Number(g.dataset.index) === index; })
      .map(function (g) { return g.querySelector(".ifg__title"); })[0];
    if (activeTitle && activeTitle.id) this.content.setAttribute("aria-labelledby", activeTitle.id);

    var decode = this.previewImg.decode ? this.previewImg.decode() : Promise.resolve();
    return decode.catch(function () {});
  };

  Transition.prototype.parts = function () {
    var slide = this.activeSlide;
    return {
      wrapper: slide.querySelector(".ifg__img"),
      caption: slide.querySelector(".ifg__caption"),
      others: this.slides.filter(function (x) { return x !== slide; })
    };
  };

  Transition.prototype.reset = function () {
    var slide = this.activeSlide;
    var wrapper = slide ? slide.querySelector(".ifg__img") : null;

    this.unmountVideo(false);
    if (wrapper) delete wrapper.dataset.flipId;

    if (this.split) this.split.revert();
    this.split = null;

    if (this.buy) gsap.set(this.buy, { clearProps: "all" });
    if (this.proof) gsap.set(this.proof, { clearProps: "all" });
    this.buy = null;
    this.proof = null;

    gsap.set(this.content, { display: "none" });
    this.content.style.removeProperty("--ifg-fade");
    this.content.setAttribute("aria-hidden", "true");
    this.content.removeAttribute("aria-labelledby");
    gsap.set(this.preview, { clearProps: "all" });
    gsap.set(this.previewImg, { clearProps: "all" });
    if (wrapper) gsap.set(wrapper, { clearProps: "all" });

    // Captions & slides that were dimmed
    gsap.set(this.slides, { clearProps: "opacity,visibility" });

    this.activeSlide = null;
    this.tl = null;
    this.state = "closed";

    // The gallery behind the dialog is no longer inert: it must be focusable again before focus goes back to it
    if (this.onClosing) this.onClosing();
    // With a real <a> inside the slide the link is the focusable element, not the <figure>
    var back = slide ? slide.querySelector(".ifg__slidelink") || slide : null;
    if (back) back.focus({ preventScroll: true });
    if (this.onClose) this.onClose();
  };

  /* ---- Video: mounted after the Flip finished, removed before the close animation ---- */

  Transition.prototype.mountVideo = function () {
    var slide = this.activeSlide;
    if (!slide || this.video || (this.state !== "opening" && this.state !== "open")) return;

    var type = slide.getAttribute("data-video-type");
    var src = slide.getAttribute("data-video-src");
    if (!type || !src) return;

    var self = this;
    var token = ++this.mountToken;
    // Visitors who asked for less motion never get a video that starts by itself
    var calm = !!(this.s.respectReducedMotion && prefersReducedMotion());
    var autoplay = slide.getAttribute("data-video-autoplay") === "1" && !calm;
    var muted = slide.getAttribute("data-video-muted") !== "0";
    var loop = slide.getAttribute("data-video-loop") === "1";
    var cover = slide.getAttribute("data-video-fit") === "cover";
    var label = this.previewImg.alt || "";

    var layer = document.createElement("div");
    layer.className = "ifg__video ifg__video--" + type + (cover ? " ifg__video--cover" : "");

    var media;
    var rec = { layer: layer, media: null, timers: [], ro: null };

    if (type === "mp4") {
      media = document.createElement("video");
      media.controls = true;
      media.playsInline = true;
      media.setAttribute("playsinline", "");
      media.muted = muted; // must be set before play() for autoplay to be allowed
      media.defaultMuted = muted;
      media.loop = loop;
      media.preload = autoplay ? "auto" : "metadata";
      media.poster = this.previewImg.currentSrc || this.previewImg.src;
      media.setAttribute("aria-label", label);
      media.src = src;
    } else {
      if (!autoplay) src = src.replace(/([?&])autoplay=1/, "$1autoplay=0");
      media = document.createElement("iframe");
      media.setAttribute("allow", "autoplay; fullscreen; picture-in-picture; encrypted-media");
      media.setAttribute("allowfullscreen", "");
      media.setAttribute("referrerpolicy", "strict-origin-when-cross-origin");
      media.setAttribute("title", label);
      media.src = src;
    }

    // Fade the player in only once it can actually show a frame, so the poster never flashes away to black
    var show = once(function () {
      if (token !== self.mountToken || self.video !== rec) return;
      gsap.to(layer, { autoAlpha: 1, duration: calm ? 0.01 : 0.45, ease: "power2.out" });
    });

    gsap.set(layer, { autoAlpha: 0 });
    layer.appendChild(media);
    this.preview.appendChild(layer);
    rec.media = media;
    this.video = rec;

    if (type === "mp4") {
      ["loadeddata", "loadedmetadata", "canplay"].forEach(function (ev) {
        media.addEventListener(ev, show, { once: true });
      });
      if (autoplay) {
        var p = media.play();
        if (p && typeof p.catch === "function") p.catch(function () {}); // blocked: the poster + controls stay
      }
    } else {
      media.addEventListener("load", function () { rec.timers.push(setTimeout(show, 200)); }, { once: true });
      if (cover && typeof ResizeObserver !== "undefined") {
        // iframes cannot object-fit: size a 16:9 box so that it covers the frame
        var fit = function () {
          var w = layer.clientWidth;
          var h = layer.clientHeight;
          if (!w || !h) return;
          var fw = Math.max(w, (h * 16) / 9);
          media.style.width = fw + "px";
          media.style.height = (fw * 9) / 16 + "px";
        };
        rec.ro = new ResizeObserver(fit);
        rec.ro.observe(layer);
        fit();
      }
    }

    rec.timers.push(setTimeout(show, 5000)); // never leave the layer invisible if no event arrives
  };

  Transition.prototype.unmountVideo = function (animate, done) {
    var rec = this.video;
    this.mountToken++; // cancels a pending mount / fade-in
    this.video = null;

    if (!rec) {
      if (done) done();
      return;
    }

    var kill = function () {
      rec.timers.forEach(clearTimeout);
      if (rec.ro) rec.ro.disconnect();
      var m = rec.media;
      if (m) {
        // Stop playback AND the download / embedded player: removing the node alone can keep audio running
        if (m.tagName === "VIDEO") {
          try { m.pause(); } catch (e) {}
          m.removeAttribute("src");
          try { m.load(); } catch (e) {}
        } else {
          m.setAttribute("src", "about:blank");
        }
      }
      if (rec.layer.parentNode) rec.layer.parentNode.removeChild(rec.layer);
      if (done) done();
    };

    gsap.killTweensOf(rec.layer);
    if (animate) {
      gsap.to(rec.layer, { autoAlpha: 0, duration: 0.18, ease: "power1.out", onComplete: kill });
    } else {
      kill();
    }
  };

  Transition.prototype.destroy = function () {
    if (this.tl) this.tl.kill();
    this.unmountVideo(false);
    if (this.split) this.split.revert();
    if (this.buy) gsap.set(this.buy, { clearProps: "all" });
    if (this.proof) gsap.set(this.proof, { clearProps: "all" });
    this.content.removeAttribute("aria-labelledby");
    gsap.set(this.content, { display: "none" });
    this.content.style.removeProperty("--ifg-fade");
    gsap.set(this.preview, { clearProps: "all" });
    gsap.set(this.previewImg, { clearProps: "all" });
  };

  /* ------------------------------------------------------------------ */
  /* Gallery controller                                                  */
  /* ------------------------------------------------------------------ */
  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  function Gallery(root, opts) {
    this.root = root;
    this.s = Object.assign({}, DEFAULTS, opts || {});
    this.listeners = [];
    this.destroyed = false;
    this.built = false;
    this.pendingRebuild = false;
    this.timers = [];

    // Category filter state. "persist" holds listeners that live as long as the Gallery (the filter bar is outside
    // the part of the DOM that gets replaced), unlike this.listeners which are torn down on every rebuild.
    this.persist = [];
    this.term = 0;
    this.reqToken = 0;
    this.buildTicket = 0;
    this.cache = {};
    this.ctl = null;
    this.filterBound = false;
    this.userPaused = false;
    this.liveTimer = null;

    var s = this.s;

    // Visitors who asked their OS for less motion get a calm version: no autoplay / parallax, short transitions
    if (s.respectReducedMotion && prefersReducedMotion()) {
      s.autoplay = false;
      s.parallax = false;
      s.smoothness = Math.min(s.smoothness, 0.25);
      s.revealDuration = Math.min(s.revealDuration, 0.3);
      s.revealStagger = Math.min(s.revealStagger, 0.04);
      s.openDuration = Math.min(s.openDuration, 0.5);
      s.closeDuration = Math.min(s.closeDuration, 0.5);
      s.textStagger = 0.002;
      s.textDelay = Math.min(s.textDelay, 0.2);
      s.captionStagger = 0.005;
    }

    // "The script is running": switches the stylesheet from the plain no-JS grid to the endless column
    root.classList.add("ifg--js");
    root.classList.add("ifg--overlay-" + (s.overlay === "fixed" ? "fixed" : "contained"));
    root.classList.add("ifg--click-" + (s.clickAction === "link" || s.clickAction === "none" ? s.clickAction : "content"));

    // "Cover the entire page" mode: the gallery fills the window and the page behind it stops scrolling
    if (s.pageMode) {
      root.classList.add("ifg--page");
      document.documentElement.classList.add("ifg-page-lock");
    }

    // Mirror the content view for right-to-left sites (PHP adds the class too; this is the fallback)
    try {
      if (getComputedStyle(root).direction === "rtl") root.classList.add("ifg--rtl");
    } catch (e) {}

    this.slideEls = toArr(".ifg__slide", root);
    if (!this.slideEls.length) return;

    this.showLoader(false);

    var self = this;
    this.waitForImages().then(function () {
      if (self.destroyed) return;
      self.whenSized(function () { self.build(); });
    });
  }

  Gallery.prototype.showLoader = function (fade) {
    if (!this.s.loader || (this.loader && this.loader.parentNode)) return;
    var loader = document.createElement("div");
    loader.className = "ifg__loader";
    loader.setAttribute("aria-hidden", "true");
    this.root.appendChild(loader);
    this.loader = loader;
    if (fade) gsap.fromTo(loader, { autoAlpha: 0 }, { autoAlpha: 1, duration: 0.3, ease: "power2.out" });
  };

  Gallery.prototype.hideLoader = function () {
    var loader = this.loader;
    if (!loader) return;
    this.loader = null;
    gsap.to(loader, {
      autoAlpha: 0,
      duration: 0.6,
      ease: "power2.out",
      onComplete: function () { if (loader.parentNode) loader.parentNode.removeChild(loader); }
    });
  };

  // Politely announce something to screen readers (the node exists from the start, so the change is picked up)
  Gallery.prototype.announce = function (msg) {
    var live = this.root.querySelector(".ifg__live");
    if (!live) return;
    clearTimeout(this.liveTimer);
    live.textContent = "";
    this.liveTimer = setTimeout(function () { live.textContent = msg; }, 60);
  };

  // Lazy-load plugins (WP Rocket, LiteSpeed, Smush, a3, Jetpack ...) park the real URL in a data attribute and
  // leave a tiny placeholder in src. Swap the real URL back in for every image. Only images PHP marks
  // loading="eager" (the first screenful) are forced eager; the rest stay native lazy.
  Gallery.prototype.prepareImage = function (img, eager) {
    try {
      if (eager) img.loading = "eager";
      img.removeAttribute("data-lazy-loaded");
      var real = img.getAttribute("data-src") || img.getAttribute("data-lazy-src") || img.getAttribute("data-original");
      var realSet = img.getAttribute("data-srcset") || img.getAttribute("data-lazy-srcset");
      var cur = img.getAttribute("src") || "";
      var isPlaceholder = !cur || /^data:image\/(gif|svg\+xml)/i.test(cur);
      if (real && isPlaceholder) img.setAttribute("src", real);
      if (realSet && !img.getAttribute("srcset")) img.setAttribute("srcset", realSet);
    } catch (e) {}
  };

  // Lazy images are not awaited: their box is sized by CSS (aspect ratio), so GSAP can measure and Flip without
  // the file. The open animation loads the full size itself, and the hover warm-up starts it early.
  Gallery.prototype.waitForImages = function () {
    var self = this;
    var eager = [];
    toArr(".ifg__img img", this.root).forEach(function (img) {
      var isEager = img.getAttribute("loading") === "eager";
      if (isEager) eager.push(img);
      self.prepareImage(img, isEager);
    });
    var all = Promise.all(
      eager.map(function (img) {
        if (img.complete && img.naturalWidth) return Promise.resolve();
        return new Promise(function (resolve) {
          img.addEventListener("load", resolve, { once: true });
          img.addEventListener("error", resolve, { once: true });
        });
      })
    );
    // One slow or broken image must never keep the loader on screen forever
    var limit = new Promise(function (resolve) {
      self.timers.push(setTimeout(resolve, 8000));
    });
    return Promise.race([all, limit]);
  };

  // Elementor may render the widget while hidden (tabs, accordions, popups, editor); wait for real dimensions
  Gallery.prototype.whenSized = function (cb) {
    var self = this;
    if (this.root.offsetHeight > 0 && this.root.offsetWidth > 0) return cb();
    if (typeof ResizeObserver === "undefined") return cb();

    var ro = new ResizeObserver(function () {
      if (self.root.offsetHeight > 0 && self.root.offsetWidth > 0) {
        ro.disconnect();
        if (!self.destroyed) cb();
      }
    });
    ro.observe(this.root);
    this.pendingRO = ro;
  };

  Gallery.prototype.on = function (target, ev, fn, opts) {
    target.addEventListener(ev, fn, opts);
    this.listeners.push([target, ev, fn, opts]);
  };

  // position:fixed is relative to the viewport only if no ancestor has transform / filter / contain / will-change
  // (Elementor entrance animations and some themes add them). Probe it and fall back to the contained overlay.
  Gallery.prototype.syncOverlay = function () {
    if (this.s.overlay !== "fixed") return;
    var root = this.root;
    var probe = document.createElement("div");
    probe.style.cssText = "position:fixed;top:0;right:0;bottom:0;left:0;visibility:hidden;pointer-events:none";
    root.appendChild(probe);
    var r = probe.getBoundingClientRect();
    root.removeChild(probe);
    // A real fixed box spans the whole viewport. If an ancestor is its containing block it only spans that ancestor.
    var vw = document.documentElement.clientWidth || window.innerWidth;
    var vh = window.innerHeight;
    var ok =
      Math.abs(r.top) < 1 &&
      Math.abs(r.left) < 1 &&
      Math.abs(r.width - vw) < 3 &&
      Math.abs(r.height - vh) <= Math.max(120, vh * 0.15);
    root.classList.toggle("ifg--overlay-fixed", ok);
    root.classList.toggle("ifg--overlay-contained", !ok);
    if (!ok && !this.warnedFixed && window.console) {
      this.warnedFixed = true;
      console.info("[IFG] A parent element has a CSS transform/filter, so the fullscreen overlay was switched to 'inside widget' automatically.");
    }
  };

  // Everything inside the open content view that can take keyboard focus, in DOM order (player, back, links, cart button)
  Gallery.prototype.focusables = function () {
    var content = this.root.querySelector(".ifg__content");
    if (!content) return [];
    return toArr(
      ".ifg__video video[controls], .ifg__video iframe, .ifg__back, .ifg__group.active a[href], .ifg__group.active button",
      content
    ).filter(function (el) {
      // aria-disabled buttons stay in (they keep focus while busy); hidden ones ("View cart" before it appears) are out
      return !el.hidden && el.getAttribute("tabindex") !== "-1" && el.getClientRects().length > 0;
    });
  };

  // Content view open: nothing behind it may be reached by keyboard, pointer or screen reader
  Gallery.prototype.setOpen = function (on) {
    var root = this.root;
    root.classList.toggle("ifg--open", !!on);
    toArr(".ifg__gallery, .ifg__frame, .ifg__filters, .ifg__pause, .ifg__skip, .ifg__hint", root).forEach(function (el) {
      if (on) el.setAttribute("inert", "");
      else el.removeAttribute("inert");
    });
  };

  Gallery.prototype.build = function () {
    var self = this;
    var root = this.root;
    var s = this.s;

    this.transition = new Transition(root, s, {
      beforeOpen: function () {
        self.syncOverlay();
        self.setOpen(true);
      },
      onClosing: function () { self.setOpen(false); },
      onClose: function () {
        self.slider.start();
        if (self.pendingRebuild) {
          self.pendingRebuild = false;
          self.slider.rebuild();
        }
      }
    });

    this.reveal = new Reveal(root, s);
    root.classList.add("ifg--ready");

    this.slider = new Slider(root, s, {
      userPaused: this.userPaused,
      enabled: function () { return self.transition.state === "closed"; },
      onToggle: function (changes, immediate) { self.reveal.toggle(changes, immediate); }
    });

    this.hideLoader();

    var action = s.clickAction === "link" || s.clickAction === "none" ? s.clickAction : "content";

    this.slideEls.forEach(function (slide, index) {
      if (action === "none") return;

      var href = slide.getAttribute("data-href");
      var mode = action === "link" && href ? "link" : "content";
      var anchor = slide.querySelector(".ifg__slidelink");

      // With a real <a> inside, the link itself is the focusable element
      if (!anchor) {
        slide.setAttribute("tabindex", "0");
        slide.setAttribute("role", mode === "link" ? "link" : "button");
      }
      // Tells assistive tech that activating the slide opens the dialog
      if (mode === "content") (anchor || slide).setAttribute("aria-haspopup", "dialog");

      var open = function () {
        if (self.transition.state !== "closed") return;
        self.slider.stop();
        self.transition.open(slide, index);
      };

      var go = function (e) {
        var blank = slide.getAttribute("data-target") === "_blank" || (e && (e.ctrlKey || e.metaKey));
        if (blank) window.open(href, "_blank", "noopener");
        else window.location.href = href;
      };

      self.on(slide, "click", function (e) {
        self.slider.engage();
        var viaAnchor = !!(anchor && e.target && e.target.closest && e.target.closest(".ifg__slidelink"));

        if (mode === "link") {
          if (!viaAnchor) go(e); // a real anchor navigates by itself
          return;
        }

        // "Open content view": keep the anchor's normal behaviour for new-tab clicks
        if (viaAnchor) {
          if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button === 1) return;
          e.preventDefault();
        }
        open();
      });

      self.on(slide, "keydown", function (e) {
        if (e.key !== "Enter" && e.key !== " ") return;
        if (anchor && e.key === "Enter") return; // the browser turns Enter on a link into a click
        e.preventDefault();
        self.slider.engage();
        if (mode === "link") go(e);
        else open();
      });

      // Warm up the full-size image so the open animation does not wait for the network
      var warm = function () {
        if (slide.__ifgWarm) return;
        slide.__ifgWarm = true;
        var im = slide.querySelector(".ifg__img img");
        var full = im && im.getAttribute("data-full");
        if (full) new Image().src = full;
      };
      self.on(slide, "pointerenter", warm);
      self.on(slide, "focusin", warm);
    });

    // "After click" scroll mode: engage on click / keyboard focus, release on Esc, outside click, leaving, scrolling away
    if (s.scrollMode === "click") {
      var canHover = window.matchMedia && window.matchMedia("(hover: hover)").matches;
      this.on(root, "pointerdown", function () { self.slider.engage(); });
      this.on(root, "focusin", function () { self.slider.engage(); });
      this.on(document, "pointerdown", function (e) {
        if (!root.contains(e.target)) self.slider.release();
      });
      this.on(document, "keydown", function (e) {
        if (e.key === "Escape" && self.transition.state === "closed") self.slider.release();
      });
      if (canHover) this.on(root, "mouseleave", function () { self.slider.release(); });
    }

    var back = root.querySelector(".ifg__back");
    if (back) this.on(back, "click", function () { self.transition.close(); });

    // Safety net for browsers without overflow:clip — focusing an off-screen slide must never scroll the widget box
    this.on(root, "scroll", function () {
      if (root.scrollTop || root.scrollLeft) {
        root.scrollTop = 0;
        root.scrollLeft = 0;
      }
    });

    if (s.closeOnEsc) {
      this.on(document, "keydown", function (e) {
        if (e.key === "Escape" && self.transition.state !== "closed") self.transition.close();
      });
    }

    // Keep keyboard focus inside the content view while it is open
    this.on(root, "keydown", function (e) {
      if (e.key !== "Tab" || self.transition.state === "closed") return;
      var items = self.focusables();
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      var active = document.activeElement;
      if (e.shiftKey && (active === first || items.indexOf(active) === -1)) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (active === last || items.indexOf(active) === -1)) {
        e.preventDefault();
        first.focus();
      }
    });

    // While the content view is open, keep the page from scrolling behind it
    var lock = function (e) {
      if (self.transition.state === "closed") return;
      if (e.target && e.target.closest && e.target.closest(".ifg__groups") && e.type === "wheel") return;
      // The video's own controls (seek bar, volume) need their touch / wheel gestures
      if (e.target && e.target.closest && e.target.closest(".ifg__video")) return;
      if (e.cancelable) e.preventDefault();
    };
    this.on(root, "wheel", lock, { passive: false });
    this.on(root, "touchmove", lock, { passive: false });

    // Rebuild when the layout settles. Watching the gallery as well as the root also covers things that change
    // slide sizes without resizing the widget: web fonts finishing to load, and live edits in the Elementor panel
    // (image size, aspect ratio, gap, padding ...).
    var timer;
    var lastW = root.offsetWidth;
    var lastH = root.offsetHeight;
    var gal = root.querySelector(".ifg__gallery");
    var lastGH = gal ? gal.offsetHeight : 0;
    if (typeof ResizeObserver !== "undefined") {
      this.ro = new ResizeObserver(function () {
        if (!root.isConnected) return self.destroy();
        var w = root.offsetWidth;
        var h = root.offsetHeight;
        var gh = gal ? gal.offsetHeight : 0;
        if (w === lastW && h === lastH && gh === lastGH) return;
        lastW = w;
        lastH = h;
        lastGH = gh;
        clearTimeout(timer);
        timer = setTimeout(function () {
          if (self.destroyed || !w || !h) return;
          if (self.transition.state === "closed") self.slider.rebuild();
          else self.pendingRebuild = true;
        }, 200);
      });
      this.ro.observe(root);
      if (gal) this.ro.observe(gal);
      this.timers.push({ clear: function () { clearTimeout(timer); } });
    }

    // v1.6 features. They are bound here (and torn down in unmount) so that every rebuild gets fresh listeners.
    this.bindCart();
    this.initPause();
    if (!this.filterBound) this.initFilters();

    this.built = true;
    root.dispatchEvent(new CustomEvent("ifg:ready", { bubbles: true }));
  };

  /* ------------------------------------------------------------------ */
  /* Teardown / rebuild                                                  */
  /* ------------------------------------------------------------------ */

  // Undo everything build() created, in reverse order, and leave the DOM and the Gallery object reusable:
  //   observers & timers  ->  listeners  ->  Transition (timeline, SplitText, player)  ->  Slider (verticalLoop timeline,
  //   scrub tween, Observer, ticker, IntersectionObserver)  ->  Reveal (SplitText on the captions)
  // After this the slide nodes can be replaced; build() can run again on the new nodes.
  Gallery.prototype.unmount = function () {
    if (this.pendingRO) { this.pendingRO.disconnect(); this.pendingRO = null; }
    if (this.ro) { this.ro.disconnect(); this.ro = null; }
    this.timers.forEach(function (t) {
      if (t && typeof t.clear === "function") t.clear();
      else clearTimeout(t);
    });
    this.timers = [];
    this.listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
    this.listeners = [];

    if (this.built) {
      this.transition.destroy();
      this.slider.destroy();
      this.reveal.destroy();
    }
    this.built = false;
    this.pendingRebuild = false;

    this.setOpen(false);
    this.root.classList.remove("ifg--ready", "ifg--engaged", "ifg--no-touch");
  };

  Gallery.prototype.destroy = function () {
    if (this.destroyed) return;
    this.destroyed = true;

    if (this.ctl && this.ctl.abort) this.ctl.abort();
    clearTimeout(this.liveTimer);
    this.persist.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
    this.persist = [];

    this.unmount();

    if (this.loader && this.loader.parentNode) this.loader.parentNode.removeChild(this.loader);

    if (this.s.pageMode) document.documentElement.classList.remove("ifg-page-lock");

    // Dropping "ifg--js" lets the stylesheet show the plain grid instead of an unstyled, dead column
    this.root.classList.remove(
      "ifg--js", "ifg--page", "ifg--ready", "ifg--overlay-fixed", "ifg--overlay-contained", "ifg--no-touch",
      "ifg--click-content", "ifg--click-link", "ifg--click-none", "ifg--open", "ifg--filtering"
    );
    delete this.root.__ifg;
  };

  /* ------------------------------------------------------------------ */
  /* WooCommerce: AJAX "Add to cart" (WooCommerce's own ?wc-ajax endpoint) */
  /* ------------------------------------------------------------------ */
  Gallery.prototype.bindCart = function () {
    var self = this;
    var cart = this.s.cart;
    if (!cart || !cart.url) return;

    // One delegated listener on the root: it covers every button, also those of a freshly filtered product set
    this.on(this.root, "click", function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.ifg__atc[data-ajax="1"]') : null;
      if (!btn || !self.root.contains(btn)) return;
      e.preventDefault();
      self.addToCart(btn, cart);
    });
  };

  Gallery.prototype.addToCart = function (btn, cart) {
    var self = this;
    // A second click while the request runs is ignored (aria-disabled semantics: the button keeps keyboard focus)
    if (btn.getAttribute("aria-busy") === "true") return;

    var st = cart.strings || {};
    var box = btn.closest(".ifg__buy");
    var label = btn.querySelector(".ifg__atc-label");
    var status = box ? box.querySelector(".ifg__atc-status") : null;
    var viewCart = box ? box.querySelector(".ifg__viewcart") : null;
    var title = btn.getAttribute("data-title") || "";
    var id = btn.getAttribute("data-product-id");
    if (!btn.__ifgLabel && label) btn.__ifgLabel = label.textContent;
    clearTimeout(btn.__ifgT);

    var setState = function (state, text) {
      btn.classList.remove("is-added", "is-error");
      if (state === "loading") btn.setAttribute("aria-busy", "true");
      else btn.removeAttribute("aria-busy");
      if (state === "added") btn.classList.add("is-added");
      if (state === "error") btn.classList.add("is-error");
      if (label) label.textContent = text;
    };
    var say = function (msg) {
      if (!status) return;
      status.textContent = "";
      setTimeout(function () { status.textContent = msg; }, 30);
    };
    var reset = function (ms) {
      btn.__ifgT = setTimeout(function () { setState("idle", btn.__ifgLabel || ""); }, ms);
    };

    setState("loading", st.adding || "…");

    var body = new URLSearchParams();
    body.set("product_id", id);
    body.set("quantity", "1");

    fetch(cart.url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        // WooCommerce answers { error: true, product_url } when the product needs options first
        if (res && res.error && res.product_url) {
          window.location.href = res.product_url;
          return;
        }
        if (!res || res.error) throw new Error("add_to_cart");

        setState("added", st.added || "Added!");
        if (viewCart) viewCart.hidden = false;
        say(fmt(st.announce, title));

        // Same event WooCommerce's own add-to-cart.js fires: mini-cart widgets, cart fragments and themes listen to it
        var $ = window.jQuery;
        if ($) {
          try { $(document.body).trigger("added_to_cart", [res.fragments, res.cart_hash, $(btn)]); } catch (err) {}
        }
        self.root.dispatchEvent(new CustomEvent("ifg:added_to_cart", { bubbles: true, detail: { productId: id, response: res } }));
        reset(2800);
      })
      .catch(function () {
        setState("error", st.error || "Error");
        say(fmt(st.failed, title));
        reset(3500);
      });
  };

  /* ------------------------------------------------------------------ */
  /* Autoplay pause button (WCAG 2.2.2)                                  */
  /* ------------------------------------------------------------------ */
  Gallery.prototype.initPause = function () {
    var self = this;
    var btn = this.root.querySelector(".ifg__pause");
    if (!btn || !this.s.autoplay) return; // reduced motion switches autoplay off: the button stays hidden

    btn.hidden = false;
    var sync = function () {
      btn.textContent = btn.getAttribute(self.userPaused ? "data-play-text" : "data-pause-text") || btn.textContent;
    };
    sync();
    this.on(btn, "click", function () {
      self.userPaused = !self.userPaused;
      self.slider.userPaused = self.userPaused;
      sync();
    });
  };

  /* ------------------------------------------------------------------ */
  /* Frontend category filter                                            */
  /* ------------------------------------------------------------------ */
  Gallery.prototype.persistOn = function (target, ev, fn, opts) {
    target.addEventListener(ev, fn, opts);
    this.persist.push([target, ev, fn, opts]);
  };

  Gallery.prototype.initFilters = function () {
    var self = this;
    var f = this.s.filter;
    var bar = this.root.querySelector(".ifg__filters");
    if (!f || !bar) return;
    this.filterBound = true;

    var gal = this.root.querySelector(".ifg__gallery");
    toArr(".ifg__filter", bar).forEach(function (b) {
      // Server markup is a plain link to the category archive (works without JS). With JS it becomes a toggle button.
      b.setAttribute("role", "button");
      b.setAttribute("aria-pressed", b.classList.contains("is-active") ? "true" : "false");
      if (gal && gal.id) b.setAttribute("aria-controls", gal.id);
    });

    var target = function (e) {
      var b = e.target && e.target.closest ? e.target.closest(".ifg__filter") : null;
      return b && bar.contains(b) ? b : null;
    };
    var pick = function (b) {
      self.applyTerm(parseInt(b.getAttribute("data-term"), 10) || 0);
    };

    this.persistOn(bar, "click", function (e) {
      var b = target(e);
      if (!b) return;
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button === 1) return; // "open category in a new tab" still works
      e.preventDefault();
      pick(b);
    });

    // Enter already clicks a link; role="button" promises Space as well
    this.persistOn(bar, "keydown", function (e) {
      if (e.key !== " " && e.key !== "Spacebar") return;
      var b = target(e);
      if (!b) return;
      e.preventDefault();
      pick(b);
    });
  };

  Gallery.prototype.syncFilterState = function () {
    var term = this.term;
    toArr(".ifg__filter", this.root).forEach(function (b) {
      var on = (parseInt(b.getAttribute("data-term"), 10) || 0) === term;
      b.classList.toggle("is-active", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  };

  Gallery.prototype.fetchTerm = function (term) {
    var self = this;
    var f = this.s.filter;
    if (this.cache[term]) return Promise.resolve(this.cache[term]);

    // Only the newest request counts
    if (this.ctl && this.ctl.abort) this.ctl.abort();
    var ctl = (this.ctl = typeof AbortController !== "undefined" ? new AbortController() : null);

    var body = new URLSearchParams();
    body.set("action", f.action);
    body.set("post", f.post);
    body.set("widget", f.widget);
    body.set("term", term);
    body.set("current", f.current || 0);

    return fetch(f.url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
      signal: ctl ? ctl.signal : undefined
    })
      .then(function (r) {
        if (!r.ok) throw new Error("http " + r.status);
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success || !json.data) throw new Error("bad response");
        self.cache[term] = json.data;
        return json.data;
      });
  };

  Gallery.prototype.applyTerm = function (term) {
    var self = this;
    var f = this.s.filter;
    var root = this.root;
    if (!f || term === this.term) return;
    // Never swap the DOM under an open content view
    if (this.transition && this.transition.state !== "closed") return;

    var prev = this.term;
    var token = ++this.reqToken;
    this.term = term;
    this.syncFilterState();

    root.classList.add("ifg--filtering");
    root.setAttribute("aria-busy", "true");

    // Cover the old content with the loader line (or dim it when the loader is switched off) while the request runs
    this.showLoader(true);
    var gal = root.querySelector(".ifg__gallery");
    if (!this.s.loader && gal) gsap.to(gal, { opacity: 0.35, duration: 0.25 });

    this.fetchTerm(term)
      .then(function (data) {
        if (self.destroyed || token !== self.reqToken) return;
        self.swap(data, term);
      })
      .catch(function (err) {
        if (self.destroyed || token !== self.reqToken || (err && err.name === "AbortError")) return;
        // The old products are still mounted: put the bar back and tell the visitor
        self.term = prev;
        self.syncFilterState();
        self.endBusy();
        if (gal) gsap.to(gal, { opacity: 1, duration: 0.25, clearProps: "opacity" });
        self.hideLoader();
        self.announce((f.strings && f.strings.error) || "");
      });
  };

  Gallery.prototype.endBusy = function () {
    this.root.classList.remove("ifg--filtering");
    this.root.removeAttribute("aria-busy");
  };

  // Replace the slides + content groups and bring the whole machinery up again on the new nodes
  Gallery.prototype.swap = function (data, term) {
    var self = this;
    var root = this.root;
    var f = this.s.filter;
    var strings = f.strings || {};

    // 1. Tear down. This must happen BEFORE the old nodes disappear: the verticalLoop timeline, the scrub tween, the
    //    Observer, the autoplay ticker, the SplitText instances and the Flip state all hold references to them.
    this.unmount();

    // 2. Swap the DOM
    var gal = root.querySelector(".ifg__gallery");
    var back = root.querySelector(".ifg__back");
    toArr(".ifg__empty", root).forEach(function (n) { n.parentNode.removeChild(n); });
    if (gal) {
      gsap.set(gal, { clearProps: "opacity,visibility" });
      gal.innerHTML = data.slides || "";
    }
    toArr(".ifg__group", root).forEach(function (n) { n.parentNode.removeChild(n); });
    if (back && data.groups) back.insertAdjacentHTML("afterend", data.groups);

    this.slideEls = toArr(".ifg__slide", root);

    var active = root.querySelector('.ifg__filter[aria-pressed="true"]');
    var name = active ? active.textContent.trim() : "";
    var count = this.slideEls.length;

    // 3. Nothing found: show a message, keep the filter bar usable
    if (!count) {
      var p = document.createElement("p");
      p.className = "ifg__empty";
      p.textContent = strings.empty || "";
      root.appendChild(p);
      root.classList.add("ifg--ready");
      this.endBusy();
      this.hideLoader();
      this.announce(strings.empty || "");
      return;
    }

    // 4. Rebuild once the new images are measurable. The ticket makes a slower, superseded swap harmless.
    var ticket = ++this.buildTicket;
    this.waitForImages().then(function () {
      if (self.destroyed || ticket !== self.buildTicket) return;
      self.whenSized(function () {
        if (self.destroyed || ticket !== self.buildTicket) return;
        self.build();
        self.endBusy();
        self.announce((name ? name + ": " : "") + fmt(count === 1 ? strings.one : strings.many, count));
        root.dispatchEvent(new CustomEvent("ifg:filtered", { bubbles: true, detail: { term: term, count: count } }));
      });
    });
  };

  /* ------------------------------------------------------------------ */
  /* Public API                                                          */
  /* ------------------------------------------------------------------ */
  function readSettings(root) {
    try {
      return JSON.parse(root.getAttribute("data-settings") || "{}");
    } catch (e) {
      return {};
    }
  }

  function init(root, opts) {
    if (!root) return null;
    if (root.__ifg) root.__ifg.destroy();
    var g = new Gallery(root, opts || readSettings(root));
    root.__ifg = g;
    return g;
  }

  function initAll(scope) {
    toArr(".ifg", scope || document).forEach(function (el) {
      if (!el.__ifg) init(el);
    });
  }

  window.IFG = { init: init, initAll: initAll, defaults: DEFAULTS };

  // Elementor integration (frontend + editor).
  // Initialising only when the element has no running instance keeps this safe even if the hook and the
  // plain fallback below both fire (e.g. delayed-JS / optimisation plugins that load the script late).
  function bindElementor() {
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) return;
    window.elementorFrontend.hooks.addAction("frontend/element_ready/ifg_gallery.default", function ($scope) {
      var node = $scope && $scope[0] ? $scope[0] : $scope;
      var el = node && node.querySelector ? node.querySelector(".ifg") : null;
      if (el && !el.__ifg) init(el);
    });
  }

  if (window.elementorFrontend && window.elementorFrontend.hooks) {
    bindElementor();
  } else {
    window.addEventListener("elementor/frontend/init", bindElementor);
  }

  // Plain HTML / script-loaded-late fallback. Also runs inside the Elementor editor, because widget scripts are
  // loaded on demand there and the element_ready event may already have fired.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { initAll(); });
  } else {
    initAll();
  }
})(window, document);