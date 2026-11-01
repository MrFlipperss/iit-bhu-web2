// Minimal ReactBits-inspired micro-interactions tailored for CIT Academic UI
(function(window) {
  // Scramble decrypt effect on text elements with [data-scramble]
  class ScrambleText {
    constructor(el) {
      this.el = el;
      this.original = el.getAttribute('data-scramble') || el.innerText;
      this.chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789·-_/";
      this.animating = false;
      this.el.addEventListener('mouseenter', () => this.play());
    }

    play() {
      if (this.animating) return;
      this.animating = true;
      let iteration = 0;
      const total = this.original.length;
      const interval = setInterval(() => {
        this.el.innerText = this.original
          .split("")
          .map((letter, index) => {
            if (letter === " " || letter === "\n" || letter === "·") return letter;
            if (index < iteration) return this.original[index];
            return this.chars[Math.floor(Math.random() * this.chars.length)];
          })
          .join("");

        if (iteration >= total) {
          clearInterval(interval);
          this.el.innerText = this.original;
          this.animating = false;
        }
        iteration += 1 / 2;
      }, 30);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-scramble]').forEach(el => new ScrambleText(el));
  });

  window.ScrambleText = ScrambleText;
})(window);
