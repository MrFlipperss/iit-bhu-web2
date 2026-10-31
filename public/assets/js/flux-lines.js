// Vanilla WebGL FluxLines effect adapted for zero-dependency high performance
// Inspired by reactbits & fluid shader aesthetics

(function(window) {
  const MAX_COLORS = 8;
  const INTRO_SECONDS = 1.8;
  const FLOW = { up: [0, 1], down: [0, -1], left: [-1, 0], right: [1, 0] };

  const VERTEX = `#version 300 es
  in vec2 aPosition;
  void main() {
    gl_Position = vec4(aPosition, 0.0, 1.0);
  }`;

  const FIELD = `
  float hash(vec3 p3) {
    p3 = fract(p3 * 0.1031);
    p3 += dot(p3, p3.zyx + 33.33);
    return fract((p3.x + p3.y) * p3.z);
  }

  float smin(float a, float b, float k) {
    float r = exp2(-a / k) + exp2(-b / k);
    return -k * log2(r);
  }

  float sinlerp(float a, float b, float w) {
    return mix(a, b, (sin(w * 3.14159265 - 3.14159265 / 2.0) + 1.0) / 2.0);
  }

  float vn(vec2 p, float s, float seed) {
    vec2 cellp = floor(p / s);
    vec2 relp = mod(p, s);
    float g1 = hash(vec3(cellp, seed));
    float g2 = hash(vec3(cellp.x + 1.0, cellp.y, seed));
    float g3 = hash(vec3(cellp.x + 1.0, cellp.y + 1.0, seed));
    float g4 = hash(vec3(cellp.x, cellp.y + 1.0, seed));
    float bx = sinlerp(g1, g2, relp.x / s);
    float tx = sinlerp(g4, g3, relp.x / s);
    return sinlerp(bx, tx, relp.y / s);
  }

  float dbn(vec2 p, float s, float seed) {
    float o = s / 2.0;
    float n0 = vn(p, s, seed);
    float n1 = vn(p + vec2(o, o), s, seed + 0.1);
    float n2 = vn(p + vec2(-o, o), s, seed + 0.2);
    float n3 = vn(p + vec2(o, -o), s, seed + 0.3);
    float n4 = vn(p + vec2(-o, -o), s, seed + 0.4);
    return (2.0 * n0 + 1.5 * n1 + 1.25 * n2 + 1.125 * n3 + n4) / 7.0;
  }
  `;

  const FRAGMENT = `#version 300 es
  precision highp float;
  precision highp int;
  uniform vec2 uResolution;
  uniform float uTime;
  uniform vec2 uFlow;
  uniform float uSpeed;
  uniform float uScale;
  uniform float uTurbulence;
  uniform float uFluidity;
  uniform float uRimWidth;
  uniform float uSharpness;
  uniform float uShimmer;
  uniform float uGlow;
  uniform float uLine;
  uniform float uMaxHalf;
  uniform vec3 uColors[${MAX_COLORS}];
  uniform int uColorCount;
  uniform vec4 uMagnet;
  uniform vec4 uSwirl;
  uniform float uReveal;
  uniform float uLight;
  uniform float uOpaque;
  uniform vec3 uBackground;
  uniform float uOpacity;
  out vec4 outColor;
  ${FIELD}

  vec3 palette(float h) {
    float x = clamp(h, 0.0, 1.0) * float(uColorCount - 1);
    int i = int(floor(x));
    int j = min(i + 1, uColorCount - 1);
    return mix(uColors[i], uColors[j], smoothstep(0.0, 1.0, x - float(i)));
  }

  void main() {
    float ref = 700.0 / max(uScale, 0.05);
    vec2 frag = gl_FragCoord.xy;
    vec2 toMagnet = (frag - uMagnet.xy) / uResolution.y;
    float pull = uMagnet.z * exp(-dot(toMagnet, toMagnet) / (uMagnet.w * uMagnet.w));
    if (pull > 0.001) {
      float angle = pull * 0.55;
      float c = cos(angle);
      float s = sin(angle);
      frag = uMagnet.xy + mat2(c, s, -s, c) * (frag - uMagnet.xy);
    }
    if (uSwirl.w > 0.0) {
      vec2 d = frag - uSwirl.xy;
      float reach = uSwirl.z;
      float angle = uSwirl.w * exp(-dot(d, d) / (reach * reach));
      float c = cos(angle);
      float s = sin(angle);
      frag = uSwirl.xy + mat2(c, s, -s, c) * d;
    }
    vec2 p = frag / uResolution.y * ref;

    float spd = 200.0 * uSpeed;
    float t = uTime;
    vec2 dir = uFlow;
    vec2 perp = vec2(-dir.y, dir.x);
    float distort1 = vn(p + perp * (t * spd), 60.0, 10.0) * 50.0 * uTurbulence;
    float distort2 = vn(p - perp * (t * spd), 120.0, 15.0) * 100.0 * uTurbulence;
    float peaks = dbn(p + distort1 + dir * (t * spd * 0.5), 40.0, 1.0);
    float peaks2 = dbn(p + distort2 - dir * (t * spd * 0.5), 40.0, 0.0);
    float field = smin(peaks, peaks2, max(uFluidity, 0.001));

    float slope = length(vec2(dFdx(field), dFdy(field)));
    float gap = abs(field - 0.4);
    float pixels = gap / max(slope, 1e-6);
    float halfBand = max(uRimWidth, 0.001) * 0.5;
    float across = max(gap / halfBand, pixels / uMaxHalf);
    float band = 5.0 * uRimWidth * (1.0 - across);
    float reveal = smoothstep(0.2, 0.85, pull);
    float shimmer = vn(p + dir * (t * spd * 0.5), 60.0, 12.0) * uShimmer * (1.0 - reveal * 0.75);
    float lit = clamp(band - shimmer, 0.0, 1.0);
    float glow = pow(lit, uSharpness) * uGlow * (1.0 + reveal * 0.25);
    float core = uLine > 0.0 ? smoothstep(uLine + 0.75, uLine - 0.75, pixels) * max(smoothstep(0.08, 0.6, glow), reveal) : 0.0;
    float shine = clamp(glow, 0.0, 1.0);
    float energy = max(shine * shine, core * 0.85) * uReveal;

    float h = clamp(0.5 + (peaks - peaks2) * 0.8, 0.0, 1.0);
    vec3 tint = palette(h);

    if (uLight > 0.5) {
      float chroma = max(tint.r, max(tint.g, tint.b)) - min(tint.r, min(tint.g, tint.b));
      vec3 ink = mix(vec3(0.07, 0.07, 0.08), tint * 0.55, clamp(chroma * 1.6, 0.0, 1.0));
      float cover = energy * uOpacity;
      outColor = uOpaque > 0.5 ? vec4(mix(uBackground, ink, cover), 1.0) : vec4(ink * cover, cover);
      return;
    }

    vec3 light = tint * energy * uOpacity;
    outColor = uOpaque > 0.5 ? vec4(uBackground + light, 1.0) : vec4(light, max(light.r, max(light.g, light.b)));
  }`;

  function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

  function compile(gl, type, source) {
    const s = gl.createShader(type);
    if (!s) return null;
    gl.shaderSource(s, source);
    gl.compileShader(s);
    if (gl.getShaderParameter(s, gl.COMPILE_STATUS)) return s;
    gl.deleteShader(s);
    return null;
  }

  function link(gl) {
    const vs = compile(gl, gl.VERTEX_SHADER, VERTEX);
    const fs = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT);
    const prog = gl.createProgram();
    if (!vs || !fs || !prog) return null;
    gl.attachShader(prog, vs);
    gl.attachShader(prog, fs);
    gl.bindAttribLocation(prog, 0, 'aPosition');
    gl.linkProgram(prog);
    gl.deleteShader(vs);
    gl.deleteShader(fs);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
      gl.deleteProgram(prog);
      return null;
    }
    const uniforms = {};
    const count = gl.getProgramParameter(prog, gl.ACTIVE_UNIFORMS);
    for (let i = 0; i < count; i++) {
      const info = gl.getActiveUniform(prog, i);
      if (info) uniforms[info.name.replace('[0]', '')] = gl.getUniformLocation(prog, info.name);
    }
    return { program: prog, uniforms };
  }

  class FluxLinesVisual {
    constructor(canvas, options = {}) {
      this.canvas = canvas;
      this.options = Object.assign({
        colors: ['#c4ff00', '#00f0ff', '#ffffff', '#222222'],
        speed: 0.45,
        scale: 1.5,
        turbulence: 0.9,
        fluidity: 0.12,
        rimWidth: 0.22,
        sharpness: 2.2,
        shimmer: 1.4,
        glow: 2.1,
        lineWidth: 1.0,
        flowDirection: 'down',
        opacity: 0.85,
        mouseInteraction: true,
        mouseStrength: 1.0,
        mouseRadius: 0.35,
        mouseDampening: 0.15,
        clickSwirl: true,
        intro: true,
        lightMode: false,
        transparent: true,
        backgroundColor: '#0a0a0c'
      }, options);

      this.gl = this.canvas.getContext('webgl2', {
        alpha: true,
        premultipliedAlpha: true,
        antialias: false,
        depth: false,
        stencil: false
      });

      if (!this.gl) {
        console.warn('WebGL2 not supported for FluxLines');
        return;
      }

      this.init();
    }

    init() {
      const gl = this.gl;
      this.prog = link(gl);
      if (!this.prog) return;

      this.buffer = gl.createBuffer();
      this.vao = gl.createVertexArray();
      gl.bindVertexArray(this.vao);
      gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
      gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
      gl.enableVertexAttribArray(0);
      gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);

      this.palette = new Float32Array(MAX_COLORS * 3);
      this.cache = new Map();
      this.probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });

      this.pointer = { x: 0, y: 0, inside: false };
      this.magnet = { x: 0, y: 0, active: 0 };
      this.swirl = { x: 0, y: 0, age: 10 };
      this.time = 0;
      this.reveal = this.options.intro ? 0 : 1;
      this.raf = 0;
      this.last = 0;
      this.visible = true;

      this.bindEvents();
      this.resize();
      this.start();
    }

    parseColor(value, fallback = [1, 1, 1]) {
      if (!value) return fallback;
      const key = String(value);
      if (this.cache.has(key)) return this.cache.get(key);
      let rgb = fallback;
      if (this.probe) {
        this.probe.clearRect(0, 0, 1, 1);
        this.probe.fillStyle = '#000000';
        this.probe.fillStyle = key;
        this.probe.fillRect(0, 0, 1, 1);
        const [r, g, b] = this.probe.getImageData(0, 0, 1, 1).data;
        rgb = [r / 255, g / 255, b / 255];
      }
      this.cache.set(key, rgb);
      return rgb;
    }

    render() {
      const gl = this.gl;
      if (!gl || !this.prog) return;
      const u = this.prog.uniforms;
      const s = this.options;
      const flow = FLOW[s.flowDirection] || FLOW.down;
      const ratio = this.canvas.width / Math.max(1, this.canvas.clientWidth || window.innerWidth);

      s.colors.slice(0, MAX_COLORS).forEach((c, idx) => {
        this.palette.set(this.parseColor(c), idx * 3);
      });

      const intro = Math.min(1, this.reveal);
      gl.viewport(0, 0, this.canvas.width, this.canvas.height);
      gl.useProgram(this.prog.program);
      gl.bindVertexArray(this.vao);

      gl.uniform2f(u.uResolution, this.canvas.width, this.canvas.height);
      gl.uniform1f(u.uTime, this.time);
      gl.uniform2f(u.uFlow, flow[0], flow[1]);
      gl.uniform1f(u.uSpeed, s.speed);
      gl.uniform1f(u.uScale, s.scale);
      gl.uniform1f(u.uTurbulence, s.turbulence);
      gl.uniform1f(u.uFluidity, s.fluidity);
      gl.uniform1f(u.uRimWidth, s.rimWidth);
      gl.uniform1f(u.uSharpness, s.sharpness);
      gl.uniform1f(u.uShimmer, s.shimmer);
      gl.uniform1f(u.uGlow, s.glow);
      gl.uniform1f(u.uLine, s.lineWidth * ratio * 0.5);
      gl.uniform1f(u.uMaxHalf, 28 * ratio);
      gl.uniform3fv(u.uColors, this.palette);
      gl.uniform1i(u.uColorCount, Math.min(s.colors.length, MAX_COLORS));
      gl.uniform4f(u.uMagnet, this.magnet.x, this.magnet.y, this.magnet.active * s.mouseStrength, s.mouseRadius);

      const age = this.swirl.age;
      const twist = 1.9 * Math.min(1, age / 0.23) * Math.exp(-Math.max(0, age - 0.23) * 1.1);
      gl.uniform4f(u.uSwirl, this.swirl.x, this.swirl.y, this.canvas.height * 0.22 * (1 + age * 0.5), age < 6 ? twist : 0);
      gl.uniform1f(u.uReveal, intro * intro * (3 - 2 * intro));
      gl.uniform1f(u.uLight, s.lightMode ? 1 : 0);
      gl.uniform1f(u.uOpaque, s.transparent ? 0 : 1);
      gl.uniform3fv(u.uBackground, this.parseColor(s.backgroundColor, [0.04, 0.04, 0.05]));
      gl.uniform1f(u.uOpacity, s.opacity);

      gl.drawArrays(gl.TRIANGLES, 0, 3);
    }

    frame(now) {
      if (!this.visible || document.hidden) return;
      const s = this.options;
      const dt = this.last ? clamp((now - this.last) / 1000, 0.0005, 0.05) : 1 / 60;
      this.last = now;

      this.time += dt;
      this.swirl.age += dt;
      if (s.intro && this.reveal < 1) {
        this.reveal = Math.min(1, this.reveal + dt / INTRO_SECONDS);
      } else {
        this.reveal = 1;
      }

      const engaged = s.mouseInteraction && this.pointer.inside;
      const follow = s.mouseDampening > 0 ? 1 - Math.exp(-dt / s.mouseDampening) : 1;
      if (engaged) {
        this.magnet.x += (this.pointer.x - this.magnet.x) * follow;
        this.magnet.y += (this.pointer.y - this.magnet.y) * follow;
      }
      const target = engaged ? 1 : 0;
      this.magnet.active += (target - this.magnet.active) * (1 - Math.exp(-dt / 0.3));
      if (Math.abs(target - this.magnet.active) < 0.001) this.magnet.active = target;

      this.render();
      this.raf = requestAnimationFrame(this.frame.bind(this));
    }

    start() {
      if (!this.raf) {
        this.raf = requestAnimationFrame(this.frame.bind(this));
      }
    }

    resize() {
      const parent = this.canvas.parentElement || document.body;
      const width = parent.clientWidth || window.innerWidth;
      const height = parent.clientHeight || window.innerHeight;
      const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      this.canvas.width = Math.max(1, Math.round(width * dpr));
      this.canvas.height = Math.max(1, Math.round(height * dpr));
      this.render();
    }

    bindEvents() {
      const parent = this.canvas.parentElement || window;
      const updatePointer = (e) => {
        const rect = this.canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        const ratio = this.canvas.width / Math.max(1, rect.width);
        this.pointer.inside = x >= 0 && y >= 0 && x <= rect.width && y <= rect.height;
        this.pointer.x = x * ratio;
        this.pointer.y = (rect.height - y) * ratio;
      };

      window.addEventListener('resize', () => this.resize());
      window.addEventListener('pointermove', updatePointer, { passive: true });
      window.addEventListener('pointerdown', (e) => {
        updatePointer(e);
        if (this.pointer.inside && this.options.clickSwirl) {
          this.swirl.x = this.pointer.x;
          this.swirl.y = this.pointer.y;
          this.swirl.age = 0;
        }
      });
      window.addEventListener('pointerleave', () => { this.pointer.inside = false; });
    }
  }

  window.FluxLinesVisual = FluxLinesVisual;
})(window);

