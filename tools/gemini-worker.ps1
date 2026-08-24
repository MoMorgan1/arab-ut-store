[CmdletBinding()]
param(
    [Parameter(Mandatory, Position = 0)]
    [string] $TaskFile,

    [switch] $ReadOnly,

    [switch] $ResumeLast
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Stop-Usage {
    param([string] $Message)

    [Console]::Error.WriteLine("gemini-worker: $Message")
    exit 2
}

function Get-GitText {
    param(
        [string] $Repository,
        [string[]] $Arguments
    )

    $output = & git -C $Repository @Arguments 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw "git $($Arguments -join ' ') failed: $($output -join [Environment]::NewLine)"
    }

    return ($output -join "`n").TrimEnd()
}

function Get-GitState {
    param([string] $Repository)

    return [pscustomobject]@{
        Branch = Get-GitText $Repository @('branch', '--show-current')
        Head = Get-GitText $Repository @('rev-parse', 'HEAD')
        Refs = Get-GitText $Repository @('for-each-ref', '--format=%(refname) %(objectname)')
        Status = Get-GitText $Repository @('status', '--porcelain=v1', '--untracked-files=all')
        StagedDiff = Get-GitText $Repository @('diff', '--cached', '--no-ext-diff', '--binary')
        UnstagedDiff = Get-GitText $Repository @('diff', '--no-ext-diff', '--binary')
    }
}

function Assert-NoReparsePoint {
    param(
        [string] $Repository,
        [string] $Candidate
    )

    $repositoryPrefix = $Repository.TrimEnd('\') + '\'
    $relativePath = $Candidate.Substring($repositoryPrefix.Length)
    $currentPath = $Repository

    foreach ($segment in $relativePath.Split([char[]] @('\', '/'), [System.StringSplitOptions]::RemoveEmptyEntries)) {
        $currentPath = Join-Path $currentPath $segment
        $item = Get-Item -LiteralPath $currentPath -Force

        if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
            Stop-Usage 'TaskFile and its repository ancestors must not be symbolic links or junctions.'
        }
    }
}

function Get-WorktreeContentFingerprint {
    param([string] $Repository)

    $pathSets = @(
        @('ls-files', '--cached'),
        @('ls-files', '--others', '--exclude-standard'),
        @('ls-files', '--others', '--ignored', '--exclude-standard')
    )
    $paths = foreach ($arguments in $pathSets) {
        $output = & git -C $Repository @arguments 2>&1

        if ($LASTEXITCODE -ne 0) {
            throw "git $($arguments -join ' ') failed: $($output -join [Environment]::NewLine)"
        }

        $output
    }
    $missingTrackedPaths = @(& git -C $Repository ls-files --deleted 2>&1)

    if ($LASTEXITCODE -ne 0) {
        throw "git ls-files --deleted failed: $($missingTrackedPaths -join [Environment]::NewLine)"
    }

    $missingPathSet = @{}

    foreach ($missingPath in $missingTrackedPaths) {
        $missingPathSet[([string] $missingPath).Replace('\', '/')] = $true
    }

    $fingerprintedPaths = @(
        $paths |
            ForEach-Object { ([string] $_).Replace('\', '/') } |
            Where-Object {
                -not [string]::IsNullOrWhiteSpace($_) -and
                -not $_.StartsWith('.worktrees/') -and
                -not $_.EndsWith('/') -and
                -not $missingPathSet.ContainsKey($_)
            } |
            Sort-Object -Unique
    )
    $fingerprintId = [guid]::NewGuid()
    $pathListFile = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-fingerprint-paths-$fingerprintId.txt"
    $hashListFile = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-fingerprint-hashes-$fingerprintId.txt"
    $errorFile = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-fingerprint-errors-$fingerprintId.txt"
    try {
        [System.IO.File]::WriteAllLines(
            $pathListFile,
            $fingerprintedPaths,
            [System.Text.UTF8Encoding]::new($false)
        )
        $hashProcess = Start-Process -FilePath 'git' -ArgumentList @(
            '-C', "`"$Repository`"", 'hash-object', '--no-filters', '--stdin-paths'
        ) -RedirectStandardInput $pathListFile -RedirectStandardOutput $hashListFile `
            -RedirectStandardError $errorFile -Wait -PassThru -NoNewWindow
        $fileHashes = @(Get-Content -LiteralPath $hashListFile)

        if ($hashProcess.ExitCode -ne 0) {
            throw "Unable to hash worktree files: $(Get-Content -LiteralPath $errorFile -Raw)"
        }
    }
    finally {
        Remove-Item -LiteralPath $pathListFile, $hashListFile, $errorFile -Force -ErrorAction SilentlyContinue
    }

    if ($fileHashes.Count -ne $fingerprintedPaths.Count) {
        throw 'Unable to compute a complete worktree content fingerprint.'
    }

    $entries = [System.Text.StringBuilder]::new()

    foreach ($missingPath in @($missingPathSet.Keys | Sort-Object)) {
        [void] $entries.AppendLine("$missingPath|missing")
    }

    for ($index = 0; $index -lt $fingerprintedPaths.Count; $index += 1) {
        [void] $entries.AppendLine("$($fingerprintedPaths[$index])|$($fileHashes[$index])")
    }

    $sha256 = [System.Security.Cryptography.SHA256]::Create()

    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($entries.ToString())
        return -join ($sha256.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') })
    }
    finally {
        $sha256.Dispose()
    }
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$relayPath = Join-Path $repoRoot '.agents\skills\agy-delegate\scripts\relay.mjs'
$repoPrefix = $repoRoot.TrimEnd('\') + '\'

if (-not (Test-Path -LiteralPath $TaskFile -PathType Leaf)) {
    Stop-Usage 'TaskFile must exist and be a regular file.'
}

$lexicalTask = [System.IO.Path]::GetFullPath($TaskFile)

if (-not $lexicalTask.StartsWith($repoPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    Stop-Usage 'TaskFile must be inside the repository.'
}

Assert-NoReparsePoint $repoRoot $lexicalTask
$resolvedTask = (Resolve-Path -LiteralPath $lexicalTask).Path

if (-not $resolvedTask.StartsWith($repoPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    Stop-Usage 'TaskFile must resolve inside the repository.'
}

if (-not [System.IO.File]::Exists($relayPath)) {
    Stop-Usage 'The pinned project-local agy-delegate relay is missing.'
}

$task = Get-Content -LiteralPath $resolvedTask -Raw

if ([string]::IsNullOrWhiteSpace($task)) {
    Stop-Usage 'Task brief is empty.'
}

$taskBytes = [System.Text.Encoding]::UTF8.GetByteCount($task)

if ($taskBytes -gt 16KB) {
    Stop-Usage "Task brief exceeds the 16 KiB limit ($taskBytes bytes)."
}

$requiredHeadings = @(
    'Objective',
    'Allowed paths',
    'Non-goals',
    'Acceptance criteria',
    'Required checks'
)

foreach ($heading in $requiredHeadings) {
    $escapedHeading = [regex]::Escape($heading)

    if ($task -notmatch "(?im)^#{1,6}\s+$escapedHeading\s*$") {
        Stop-Usage "Task brief is missing the required '$heading' heading."
    }
}

$temporaryBrief = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-gemini-brief-$([guid]::NewGuid()).md"
$relayOutDir = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-gemini-run-$([guid]::NewGuid())"
$apiKeyNames = @(
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'GOOGLE_APPLICATION_CREDENTIALS'
)
$savedApiKeys = @{}
$beforeGit = Get-GitState $repoRoot
$beforeContentFingerprint = if ($ReadOnly) { Get-WorktreeContentFingerprint $repoRoot } else { $null }
$gitDirValue = Get-GitText $repoRoot @('rev-parse', '--git-dir')
$gitDir = if ([System.IO.Path]::IsPathRooted($gitDirValue)) {
    [System.IO.Path]::GetFullPath($gitDirValue)
}
else {
    [System.IO.Path]::GetFullPath((Join-Path $repoRoot $gitDirValue))
}
$conversationStatePath = Join-Path $gitDir 'delegate-skills\gemini-worker-conversation.json'
$conversationId = $null

if ($ResumeLast) {
    if (-not (Test-Path -LiteralPath $conversationStatePath -PathType Leaf)) {
        Stop-Usage 'No repository-bound Gemini conversation is available to resume.'
    }

    $conversationState = Get-Content -LiteralPath $conversationStatePath -Raw | ConvertFrom-Json
    $storedRepository = [string] $conversationState.repository
    $storedConversationId = [string] $conversationState.conversationId
    $parsedConversationId = [guid]::Empty

    if (-not $storedRepository.Equals($repoRoot, [System.StringComparison]::OrdinalIgnoreCase) -or
        -not [guid]::TryParse($storedConversationId, [ref] $parsedConversationId)) {
        Stop-Usage 'The stored Gemini conversation is not valid for this repository.'
    }

    $conversationId = $storedConversationId
}

$workerRules = @"
<worker_rules>
Work only inside this repository: $repoRoot
Work only within the brief's Allowed paths.
Read and obey the repository AGENTS.md. Preserve all pre-existing changes.
Never run git add, commit, push, pull, fetch, reset, restore, checkout, rebase,
merge, cherry-pick, clean, stash, switch, branch, or worktree commands. Never
edit .git. Never run destructive filesystem commands. Never inspect .env,
credential stores, browser profiles, or files outside the repository. Never
print secrets. Never request or use dangerous permission bypasses. Make only
the requested edits, run only the listed checks, and report changed files,
check outcomes, deviations, and remaining risks.
</worker_rules>
"@

try {
    Set-Content -LiteralPath $temporaryBrief -Encoding UTF8 -Value "$workerRules`n`n$task"

    foreach ($name in $apiKeyNames) {
        $savedApiKeys[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }

    $relayArgs = @(
        $relayPath,
        '--brief', $temporaryBrief,
        '--cd', $repoRoot,
        '--lane', 'feature',
        '--sandbox',
        '--out-dir', $relayOutDir
    )

    if ($ReadOnly) {
        $relayArgs += '--read-only'
    }

    if ($ResumeLast) {
        $relayArgs += @('--conversation', $conversationId)
    }

    & node @relayArgs
    $relayExitCode = $LASTEXITCODE
}
finally {
    foreach ($name in $apiKeyNames) {
        [Environment]::SetEnvironmentVariable($name, $savedApiKeys[$name], 'Process')
    }

    if (Test-Path -LiteralPath $temporaryBrief) {
        Remove-Item -LiteralPath $temporaryBrief -Force
    }
}

$afterGit = Get-GitState $repoRoot
$afterContentFingerprint = if ($ReadOnly) { Get-WorktreeContentFingerprint $repoRoot } else { $null }
$gitHistoryChanged =
    $beforeGit.Branch -ne $afterGit.Branch -or
    $beforeGit.Head -ne $afterGit.Head -or
    $beforeGit.Refs -ne $afterGit.Refs
$readOnlyFilesChanged =
    $beforeGit.Status -ne $afterGit.Status -or
    $beforeGit.StagedDiff -ne $afterGit.StagedDiff -or
    $beforeGit.UnstagedDiff -ne $afterGit.UnstagedDiff -or
    $beforeContentFingerprint -ne $afterContentFingerprint

Write-Output '--- worker review: git status ---'
Write-Output $afterGit.Status
Write-Output '--- worker review: staged diff ---'
Write-Output $afterGit.StagedDiff
Write-Output '--- worker review: unstaged diff ---'
Write-Output $afterGit.UnstagedDiff

if ($gitHistoryChanged) {
    [Console]::Error.WriteLine('gemini-worker: HIGH SEVERITY - Git history or refs changed during the worker run. No automatic recovery was attempted.')
    exit 70
}

if ($ReadOnly -and $readOnlyFilesChanged) {
    [Console]::Error.WriteLine('gemini-worker: HIGH SEVERITY - read-only worker changed repository files. No automatic recovery was attempted.')
    exit 71
}

$resultPath = Join-Path $relayOutDir 'result.json'

if ($relayExitCode -eq 0 -and (Test-Path -LiteralPath $resultPath -PathType Leaf)) {
    $relayResult = Get-Content -LiteralPath $resultPath -Raw | ConvertFrom-Json
    $reportedConversationId = [string] $relayResult.conversationId
    $parsedReportedId = [guid]::Empty

    if ([guid]::TryParse($reportedConversationId, [ref] $parsedReportedId)) {
        $stateDirectory = Split-Path -Parent $conversationStatePath
        New-Item -ItemType Directory -Path $stateDirectory -Force | Out-Null
        [pscustomobject]@{
            repository = $repoRoot
            conversationId = $reportedConversationId
            updatedAt = [DateTimeOffset]::UtcNow.ToString('O')
        } | ConvertTo-Json | Set-Content -LiteralPath $conversationStatePath -Encoding UTF8
    }
}

exit $relayExitCode
