// Backend address; the frontend never talks to Wash Pal directly.
const API = 'http://127.0.0.1:8081';

const el = (id) => document.getElementById(id);
const ACTIVE = ['starting', 'running'];

async function call(path, options) {
  const res = await fetch(API + path, options);
  return { status: res.status, body: await res.json().catch(() => ({})) };
}

// textContent only: the error text originates from an upstream service.
function render(run, message = '') {
  const text = (run && run.error) || message;
  el('message').textContent = text;
  el('message').className = run && run.error ? 'error' : '';

  const active = !!run && ACTIVE.includes(run.status);
  el('start').disabled = active;

  const done = !!run && run.status === 'done';
  el('download').className = done ? '' : 'disabled';
  el('download').href = done ? API + '/runs/current/csv' : '#';

  el('chart').hidden = !done;
  if (done && chartedRun !== run.id) {
    chartedRun = run.id;
    // Reset on failure so the next poll retries.
    drawChart().catch(() => { chartedRun = null; });
  }

  if (!run) return;
  el('bar').max = run.target;
  el('bar').value = run.leader_count;
  el('status').textContent = run.status;
  el('phase').textContent = run.phase;
  el('leader').textContent = run.leader_node === null ? '-' : `node ${run.leader_node} (${run.leader_count}/${run.target})`;
  el('valid').textContent = run.valid;
  el('discarded').textContent = run.discarded;
  el('requests').textContent = run.requests;
  el('match').textContent = run.server_csv_match === null ? '-' : run.server_csv_match ? 'yes' : 'no';
}

let timer;
let chartedRun = null;

const SVG_NS = 'http://www.w3.org/2000/svg';
const COLORS = ['#1f77b4', '#d62728', '#2ca02c', '#ff7f0e', '#9467bd', '#8c564b', '#e377c2', '#17becf'];

function svgEl(tag, attrs, text) {
  const node = document.createElementNS(SVG_NS, tag);
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
  if (text !== undefined) node.textContent = text;
  return node;
}

// Plots the downloaded CSV: one line per node+type, x is the reading number.
async function drawChart() {
  const res = await fetch(API + '/runs/current/csv');
  if (!res.ok) throw new Error('csv not ready');
  const rows = (await res.text()).trim().split('\r\n').slice(1).map((line) => line.split(','));

  const series = new Map();
  rows.forEach(([, node, type, temp], i) => {
    const key = `${node} ${type}`;
    if (!series.has(key)) series.set(key, []);
    series.get(key).push([i, parseFloat(temp)]);
  });

  const temps = rows.map((r) => parseFloat(r[3]));
  const yMin = Math.floor(Math.min(...temps));
  // Range is a multiple of 4 so the four grid steps land on whole degrees.
  const yMax = yMin + Math.ceil((Math.max(...temps) - yMin) / 4) * 4;
  const box = { left: 40, right: 590, top: 10, bottom: 270 };
  const x = (i) => box.left + (i / Math.max(rows.length - 1, 1)) * (box.right - box.left);
  const y = (t) => box.bottom - ((t - yMin) / Math.max(yMax - yMin, 1)) * (box.bottom - box.top);

  const plot = el('plot');
  plot.replaceChildren();
  plot.append(svgEl('line', { x1: box.left, y1: box.top, x2: box.left, y2: box.bottom, stroke: '#888' }));
  plot.append(svgEl('line', { x1: box.left, y1: box.bottom, x2: box.right, y2: box.bottom, stroke: '#888' }));
  for (let n = 0; n <= 4; n++) {
    const t = yMin + ((yMax - yMin) * n) / 4;
    plot.append(svgEl('line', { x1: box.left, y1: y(t), x2: box.right, y2: y(t), stroke: '#ddd' }));
    plot.append(svgEl('text', { x: box.left - 5, y: y(t) + 4, 'text-anchor': 'end', 'font-size': 11 }, t.toFixed(0)));
  }
  plot.append(svgEl('text', { x: box.left, y: 290, 'font-size': 11 }, `${rows[0][0]} UTC`));
  plot.append(svgEl('text', { x: box.right, y: 290, 'text-anchor': 'end', 'font-size': 11 }, `${rows[rows.length - 1][0]} UTC`));

  const legend = el('legend');
  legend.replaceChildren();
  [...series].forEach(([key, points], n) => {
    const color = COLORS[n % COLORS.length];
    plot.append(svgEl('polyline', {
      points: points.map(([i, t]) => `${x(i)},${y(t)}`).join(' '),
      fill: 'none',
      stroke: color,
      'stroke-width': 1.5,
    }));
    // A series with a single reading would otherwise be invisible.
    if (points.length === 1) plot.append(svgEl('circle', { cx: x(points[0][0]), cy: y(points[0][1]), r: 2.5, fill: color }));
    const item = document.createElement('span');
    item.textContent = `${key} (${points.length})`;
    item.style.color = color;
    legend.append(item);
  });
}

// Polling also restores the view after the page is closed and reopened mid-run.
async function poll() {
  let delay = 5000;
  try {
    const { body } = await call('/runs/current');
    render(body.run);
    if (body.run && ACTIVE.includes(body.run.status)) delay = 1000;
  } catch {
    render(null, 'Backend unreachable');
  }
  // Single timer so a manual poll never leaves two loops running.
  clearTimeout(timer);
  timer = setTimeout(poll, delay);
}

el('start').addEventListener('click', async () => {
  el('start').disabled = true;
  try {
    const { status, body } = await call('/runs', { method: 'POST' });
    // 409: another run is active (possibly from another tab); show that one instead.
    render(body.run, status === 409 ? 'A run is already in progress' : '');
  } catch {
    render(null, 'Backend unreachable');
  }
  poll();
});

poll();
