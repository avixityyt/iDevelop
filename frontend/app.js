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
