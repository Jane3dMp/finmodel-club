// Статический сервер для локального осмотра index.html в браузере (замеры вёрстки).
// Запуск: node scripts/static-server.cjs   (порт 8199)
const http = require('http'), fs = require('fs'), path = require('path');
const root = path.join(__dirname, '..');
const TYPES = { '.html':'text/html; charset=utf-8', '.js':'text/javascript', '.css':'text/css',
                '.json':'application/json', '.svg':'image/svg+xml', '.png':'image/png' };
http.createServer((req, res) => {
  let p = decodeURIComponent(req.url.split('?')[0]);
  if (p === '/' ) p = '/index.html';
  const f = path.join(root, p);
  if (!f.startsWith(root) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('нет'); }
  res.writeHead(200, { 'Content-Type': TYPES[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
}).listen(8199, () => console.log('http://localhost:8199'));
