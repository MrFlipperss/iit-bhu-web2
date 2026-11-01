const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

const DEFAULT_PORT = parseInt(process.env.PORT, 10) || 8000;

function startNodeServer(port = DEFAULT_PORT) {
  const MIME_TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon',
    '.zip': 'application/zip'
  };

  const publicDir = path.join(__dirname, 'public');

  const server = http.createServer((req, res) => {
    let reqPath = decodeURI(req.url.split('?')[0]);
    if (reqPath === '/' || reqPath === '') reqPath = '/index.html';

    let filePath = path.join(publicDir, reqPath);
    if (!filePath.startsWith(publicDir)) {
      res.writeHead(403);
      return res.end('Forbidden');
    }

    fs.stat(filePath, (err, stats) => {
      if (err || !stats.isFile()) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ ok: false, error: { code: 'not_found', message: 'Not found: ' + reqPath } }));
      }

      const ext = path.extname(filePath).toLowerCase();
      const contentType = MIME_TYPES[ext] || 'application/octet-stream';

      res.writeHead(200, {
        'Content-Type': contentType,
        'Cache-Control': 'no-cache'
      });
      fs.createReadStream(filePath).pipe(res);
    });
  });

  server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
      const nextPort = port === 8000 ? 8080 : port === 8080 ? 3000 : port + 1;
      console.log(`Port ${port} is currently in use. Trying port ${nextPort}...`);
      startNodeServer(nextPort);
    } else {
      console.error('Server error:', err.message);
    }
  });

  server.listen(port, '0.0.0.0', () => {
    console.log('\n======================================================');
    console.log('CIT Campus Scheduler running locally!');
    console.log(`Open your browser at: http://localhost:${port}`);
    console.log('======================================================\n');
    console.log('Press Ctrl+C to stop.');
  });
}

// Look for php executable
const candidatePaths = [
  'php',
  path.join(process.env.LOCALAPPDATA || '', 'Microsoft', 'WinGet', 'Packages', 'PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe', 'php.exe'),
  'C:\\php\\php.exe',
  'C:\\tools\\php\\php.exe'
];

let phpBin = null;
for (const p of candidatePaths) {
  if (p === 'php') continue;
  if (fs.existsSync(p)) {
    phpBin = p;
    break;
  }
}

if (!phpBin) {
  try {
    const test = spawn('php', ['-v']);
    test.on('error', () => {
      startNodeServer();
    });
    test.on('close', (code) => {
      if (code === 0) {
        launchPhp('php');
      } else {
        startNodeServer();
      }
    });
  } catch (e) {
    startNodeServer();
  }
} else {
  launchPhp(phpBin);
}

function launchPhp(bin) {
  console.log('\n======================================================');
  console.log(`Starting CIT Campus Scheduler dev server using: ${bin}`);
  console.log(`Server URL: http://localhost:${DEFAULT_PORT}`);
  console.log('======================================================\n');

  const server = spawn(bin, ['-S', `0.0.0.0:${DEFAULT_PORT}`, '-t', 'public', 'dev/router.php'], {
    stdio: 'inherit'
  });

  server.on('error', () => {
    console.log('PHP launch error. Falling back to integrated Node server...');
    startNodeServer();
  });

  server.on('close', (code) => {
    process.exit(code || 0);
  });
}
