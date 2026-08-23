$ErrorActionPreference = 'Stop'

Describe 'gemini-worker' {
    BeforeEach {
        $script:fixtureRoot = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-gemini-worker-$([guid]::NewGuid())"
        $script:wrapperSource = Join-Path $PSScriptRoot '..\gemini-worker.ps1'
        $script:fakeRelaySource = Join-Path $PSScriptRoot 'fixtures\fake-relay.mjs'
        $script:wrapperPath = Join-Path $fixtureRoot 'tools\gemini-worker.ps1'
        $script:relayPath = Join-Path $fixtureRoot '.agents\skills\agy-delegate\scripts\relay.mjs'
        $script:briefPath = Join-Path $fixtureRoot 'brief.md'
        $script:capturePath = Join-Path ([System.IO.Path]::GetTempPath()) "worker-capture-$([guid]::NewGuid()).json"

        New-Item -ItemType Directory -Path (Split-Path -Parent $wrapperPath) -Force | Out-Null
        New-Item -ItemType Directory -Path (Split-Path -Parent $relayPath) -Force | Out-Null
        Copy-Item -LiteralPath $wrapperSource -Destination $wrapperPath
        Copy-Item -LiteralPath $fakeRelaySource -Destination $relayPath

        Set-Content -LiteralPath $briefPath -Encoding UTF8 -Value @'
# Objective
Inspect the package manifests and report their validation scripts.

# Allowed paths
- composer.json
- package.json

# Non-goals
- Do not edit application files.

# Acceptance criteria
- Report the detected validation scripts.

# Required checks
- git status --short
'@

        Push-Location $fixtureRoot
        git init --quiet
        git config user.email 'worker-test@example.invalid'
        git config user.name 'Worker Test'
        git add -- brief.md tools/gemini-worker.ps1 .agents/skills/agy-delegate/scripts/relay.mjs
        git commit --quiet -m 'test fixture'
        Pop-Location
    }

    AfterEach {
        Remove-Item -LiteralPath $fixtureRoot -Recurse -Force
        Remove-Item -LiteralPath $capturePath -Force -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_CAPTURE -ErrorAction SilentlyContinue
        Remove-Item Env:GEMINI_API_KEY -ErrorAction SilentlyContinue
        Remove-Item Env:GOOGLE_API_KEY -ErrorAction SilentlyContinue
        Remove-Item Env:GOOGLE_APPLICATION_CREDENTIALS -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_FAKE_EXIT -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_FAKE_ACTION -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_FAKE_TARGET -ErrorAction SilentlyContinue
    }

    It 'dispatches a valid brief through the safe feature lane' {
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:GEMINI_API_KEY = 'test-only-value'
        $env:GOOGLE_API_KEY = 'test-only-value'
        $env:GOOGLE_APPLICATION_CREDENTIALS = 'test-only-value'

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath *> $null
        $exitCode = $LASTEXITCODE
        $capture = Get-Content -LiteralPath $capturePath -Raw | ConvertFrom-Json

        $exitCode | Should Be 0
        ($capture.args -contains '--lane') | Should Be $true
        ($capture.args -contains 'feature') | Should Be $true
        ($capture.args -contains '--sandbox') | Should Be $true
        ($capture.args -contains '--dangerously-skip-permissions') | Should Be $false
        $capture.apiKeysPresent | Should Be $false
    }

    It 'rejects an empty brief before dispatch' {
        Set-Content -LiteralPath $briefPath -Value ''
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath 2>&1 | Out-String
        $exitCode = $LASTEXITCODE

        $exitCode | Should Be 2
        $output | Should Match 'empty'
        (Test-Path -LiteralPath $capturePath) | Should Be $false
    }

    It 'rejects a brief missing required contract headings' {
        Set-Content -LiteralPath $briefPath -Value "# Objective`nDo something bounded."
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath 2>&1 | Out-String
        $exitCode = $LASTEXITCODE

        $exitCode | Should Be 2
        $output | Should Match 'Allowed paths'
        (Test-Path -LiteralPath $capturePath) | Should Be $false
    }

    It 'rejects a brief larger than the Windows-safe limit' {
        Add-Content -LiteralPath $briefPath -Value ('x' * 17000)
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath 2>&1 | Out-String
        $exitCode = $LASTEXITCODE

        $exitCode | Should Be 2
        $output | Should Match '16 KiB'
        (Test-Path -LiteralPath $capturePath) | Should Be $false
    }

    It 'rejects a task file outside the repository' {
        $outsideBrief = Join-Path ([System.IO.Path]::GetTempPath()) "outside-brief-$([guid]::NewGuid()).md"
        Copy-Item -LiteralPath $briefPath -Destination $outsideBrief
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        try {
            $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $outsideBrief 2>&1 | Out-String
            $exitCode = $LASTEXITCODE

            $exitCode | Should Be 2
            $output | Should Match 'inside the repository'
            (Test-Path -LiteralPath $capturePath) | Should Be $false
        }
        finally {
            Remove-Item -LiteralPath $outsideBrief -Force
        }
    }

    It 'passes read-only mode to the relay' {
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -ReadOnly *> $null
        $exitCode = $LASTEXITCODE
        $capture = Get-Content -LiteralPath $capturePath -Raw | ConvertFrom-Json

        $exitCode | Should Be 0
        ($capture.args -contains '--read-only') | Should Be $true
    }

    It 'resumes only the repository-bound conversation' {
        $conversationId = '37f682d4-6bb7-47e1-9b56-96d4afeff0b9'
        $stateDir = Join-Path $fixtureRoot '.git\delegate-skills'
        New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
        [pscustomobject]@{
            repository = $fixtureRoot
            conversationId = $conversationId
        } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $stateDir 'gemini-worker-conversation.json')
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -ResumeLast *> $null
        $exitCode = $LASTEXITCODE
        $capture = Get-Content -LiteralPath $capturePath -Raw | ConvertFrom-Json

        $exitCode | Should Be 0
        ($capture.args -contains '--conversation') | Should Be $true
        ($capture.args -contains $conversationId) | Should Be $true
        ($capture.args -contains '--resume-last') | Should Be $false
    }

    It 'propagates a relay failure exit code' {
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:ARABUT_WORKER_FAKE_EXIT = '17'

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath *> $null

        $LASTEXITCODE | Should Be 17
    }

    It 'fails loudly when the worker changes Git history or refs' {
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:ARABUT_WORKER_FAKE_ACTION = 'change-ref'

        $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath 2>&1 | Out-String
        $exitCode = $LASTEXITCODE

        $exitCode | Should Be 70
        $output | Should Match 'Git history or refs changed'

        Push-Location $fixtureRoot
        $branch = git branch --show-current
        Pop-Location

        $branch | Should Be 'forbidden'
    }

    It 'fails closed when a read-only worker changes a file' {
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:ARABUT_WORKER_FAKE_ACTION = 'write-file'

        $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -ReadOnly 2>&1 | Out-String
        $exitCode = $LASTEXITCODE

        $exitCode | Should Be 71
        $output | Should Match 'read-only worker changed repository files'
        (Test-Path -LiteralPath (Join-Path $fixtureRoot 'read-only-violation.txt')) | Should Be $true
    }

    It 'detects modification of a pre-existing untracked file in read-only mode' {
        Set-Content -LiteralPath (Join-Path $fixtureRoot 'scratch.txt') -Value 'before'
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:ARABUT_WORKER_FAKE_ACTION = 'modify-existing'
        $env:ARABUT_WORKER_FAKE_TARGET = 'scratch.txt'

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -ReadOnly *> $null

        $LASTEXITCODE | Should Be 71
    }

    It 'preserves a pre-existing tracked deletion during read-only dispatch' {
        $deletedPath = Join-Path $fixtureRoot 'deleted-before-worker.txt'
        Set-Content -LiteralPath $deletedPath -Value 'tracked'
        Push-Location $fixtureRoot
        git add -- deleted-before-worker.txt
        git commit --quiet -m 'tracked deletion fixture'
        Pop-Location
        Remove-Item -LiteralPath $deletedPath -Force
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -ReadOnly *> $null

        $LASTEXITCODE | Should Be 0
        (Test-Path -LiteralPath $deletedPath) | Should Be $false
    }
}
