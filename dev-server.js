const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');

// Check if server is already running on port 8000
function checkRunning(callback) {
  const req = http.get('http://127.0.0.1:8000/index.html', (res) => {
    callback(true);
  });
  req.on('error', () => {
    callback(false);
  });
  req.setTimeout(1000, () => {
    req.destroy();
    callback(false);
  });
}

checkRunning((isRunning) => {
  if (isRunning) {
    console.log('\n======================================================');
    console.log('CIT Campus Scheduler is ALREADY RUNNING locally!');
    console.log('Open your browser at: http://localhost:8000');
    console.log('======================================================\n');
    console.log('Press Ctrl+C to stop.');
    // Keep process alive so npm run dev doesn't immediately exit
    setInterval(() => {}, 10000);
    return;
  }

  // Look for php executable
  const candidatePaths = [
    'php',
    path.join(process.env.LOCALAPPDATA || '', 'Microsoft', 'WinGet', 'Packages', 'PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe', 'php.exe'),
    'C:\\php\\php.exe',
    'C:\\tools\\php\\php.exe'
  ];

  let phpBin = 'php';
  for (const p of candidatePaths) {
    if (p === 'php') continue;
    if (fs.existsSync(p)) {
      phpBin = p;
      break;
    }
  }

  console.log('\n======================================================');
  console.log(`Starting CIT Campus Scheduler dev server using: ${phpBin}`);
  console.log('Server URL: http://localhost:8000');
  console.log('======================================================\n');

  const server = spawn(phpBin, ['-S', '0.0.0.0:8000', '-t', 'public', 'dev/router.php'], {
    stdio: 'inherit'
  });

  server.on('error', (err) => {
    console.error('Failed to start PHP server:', err.message);
    process.exit(1);
  });

  server.on('close', (code) => {
    process.exit(code || 0);
  });
});
