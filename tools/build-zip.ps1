# Build wp-omni-auth.zip for WP backend update testing.
# Syncs the plugin source into build/wp-omni-auth (excluding dev/test dirs)
# and zips it with forward-slash entry names (so Linux `unzip` extracts a
# proper wp-omni-auth/ directory instead of backslash-named files).

$ErrorActionPreference = 'Stop'
$src  = 'd:\code\wp-omni-auth'
$out  = Join-Path $src 'build\wp-omni-auth'
$zip  = Join-Path $src 'build\wp-omni-auth.zip'

# 1) Sync source -> build/wp-omni-auth (mirror, but do NOT purge so the
#    compiled .mo stays if the source lacks it). Pass each robocopy arg as
#    a separate array element so /XD dirs are not merged into one token.
$roboArgs = @(
    $src, $out,
    '/E', '/R:2', '/W:2', '/NFL', '/NDL', '/NJH', '/NJS',
    '/XF', '*.zip', '.phpunit.result.cache', '*.code-workspace', 'composer.lock',
    '/XD', '.git', '.github', 'vendor', 'docs', 'tests', 'build', '.codebuddy', '.workbuddy'
)
& robocopy @roboArgs

# 2) Remove any stale zip entry first.
if (Test-Path $zip) { Remove-Item $zip -Force }

# 3) Zip with forward slashes and a wp-omni-auth/ prefix using ZipArchive.
Add-Type -AssemblyName 'System.IO.Compression'
Add-Type -AssemblyName 'System.IO.Compression.FileSystem'
$files = Get-ChildItem -Path $out -Recurse -File
$zipStream = [System.IO.File]::Open($zip, 'Create', 'Write')
$archive = New-Object System.IO.Compression.ZipArchive($zipStream, 'Create')
foreach ($f in $files) {
    $rel = $f.FullName.Substring($out.Length + 1).Replace('\', '/')
    $entryName = 'wp-omni-auth/' + $rel
    $entry = $archive.CreateEntry($entryName)
    $src = [System.IO.File]::OpenRead($f.FullName)
    $dst = $entry.Open()
    $src.CopyTo($dst)
    $src.Dispose()
    $dst.Dispose()
}
$archive.Dispose()
$zipStream.Dispose()

# 4) Validate: list top-level entries (should be only "wp-omni-auth/") and
#    confirm zero backslashes in the archive.
$zipArchive = [System.IO.Compression.ZipFile]::OpenRead($zip)
$bs = 0
$top = @{}
foreach ($e in $zipArchive.Entries) {
    if ($e.FullName.Contains('\')) { $bs++ }
    $top[$e.FullName.Split('/')[0]] = $true
}
$zipArchive.Dispose()

Write-Host "ZIP: $zip"
Write-Host ("Size: {0} KB" -f [math]::Round((Get-Item $zip).Length / 1KB, 1))
Write-Host ("Entries: {0}" -f $files.Count)
Write-Host ("Backslash entries: {0}" -f $bs)
Write-Host ("Top-level dirs: {0}" -f ($top.Keys -join ', '))
if ($bs -gt 0) { Write-Error 'Found backslash entries — zip is broken.' }
