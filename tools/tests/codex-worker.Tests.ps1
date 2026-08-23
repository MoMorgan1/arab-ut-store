$ErrorActionPreference = 'Stop'

Describe 'codex-worker' {
    BeforeEach {
        $script:fixtureRoot = Join-Path ([System.IO.Path]::GetTempPath()) "arabut-codex-worker-$([guid]::NewGuid())"
        $script:wrapperSource = Join-Path $PSScriptRoot '..\codex-worker.ps1'
        $script:fakeRelaySource = Join-Path $PSScriptRoot 'fixtures\fake-relay.mjs'
        $script:wrapperPath = Join-Path $fixtureRoot 'tools\codex-worker.ps1'
        $script:relayPath = Join-Path $fixtureRoot '.agents\skills\codex-delegate\scripts\relay.mjs'
        $script:briefPath = Join-Path $fixtureRoot 'brief.md'
        $script:capturePath = Join-Path ([System.IO.Path]::GetTempPath()) "worker-capture-$([guid]::NewGuid()).json"

        New-Item -ItemType Directory -Path (Split-Path -Parent $wrapperPath) -Force | Out-Null
        New-Item -ItemType Directory -Path (Split-Path -Parent $relayPath) -Force | Out-Null
        Copy-Item -LiteralPath $wrapperSource -Destination $wrapperPath
        Copy-Item -LiteralPath $fakeRelaySource -Destination $relayPath

        Set-Content -LiteralPath $briefPath -Encoding UTF8 -Value @'
# Objective
Add one bounded test case.

# Allowed paths
- tests/example.test.ts

# Non-goals
- Do not edit application code.

# Acceptance criteria
- The requested behavior is covered.

# Required checks
- npm test -- tests/example.test.ts
'@

        Push-Location $fixtureRoot
        git init --quiet
        git config user.email 'worker-test@example.invalid'
        git config user.name 'Worker Test'
        git add -- brief.md tools/codex-worker.ps1 .agents/skills/codex-delegate/scripts/relay.mjs
        git commit --quiet -m 'test fixture'
        Pop-Location
    }

    AfterEach {
        Remove-Item -LiteralPath $fixtureRoot -Recurse -Force
        Remove-Item -LiteralPath $capturePath -Force -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_CAPTURE -ErrorAction SilentlyContinue
        Remove-Item Env:OPENAI_API_KEY -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_FAKE_ACTION -ErrorAction SilentlyContinue
        Remove-Item Env:ARABUT_WORKER_FAKE_TARGET -ErrorAction SilentlyContinue
    }

    It 'dispatches a valid brief through the selected Luna lane' {
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:OPENAI_API_KEY = 'test-only-value'

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -Lane fast *> $null
        $exitCode = $LASTEXITCODE
        $capture = Get-Content -LiteralPath $capturePath -Raw | ConvertFrom-Json

        $exitCode | Should Be 0
        ($capture.args -contains '--lane') | Should Be $true
        ($capture.args -contains 'fast') | Should Be $true
        ($capture.args -contains '--sandbox') | Should Be $true
        ($capture.args -contains 'workspace-write') | Should Be $true
        ($capture.args -contains '--dangerously-bypass-approvals-and-sandbox') | Should Be $false
        $capture.apiKeysPresent | Should Be $false
    }

    It 'rejects an incomplete brief before dispatch' {
        Set-Content -LiteralPath $briefPath -Value "# Objective`nAdd a test."
        $env:ARABUT_WORKER_CAPTURE = $capturePath

        $output = & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -Lane tests 2>&1 | Out-String
        $exitCode = $LASTEXITCODE

        $exitCode | Should Be 2
        $output | Should Match 'Allowed paths'
        (Test-Path -LiteralPath $capturePath) | Should Be $false
    }

    It 'detects modification of a pre-existing ignored file in read-only mode' {
        Set-Content -LiteralPath (Join-Path $fixtureRoot '.gitignore') -Value 'ignored.txt'
        Push-Location $fixtureRoot
        git add -- .gitignore
        git commit --quiet -m 'ignore fixture'
        Pop-Location
        Set-Content -LiteralPath (Join-Path $fixtureRoot 'ignored.txt') -Value 'before'
        $env:ARABUT_WORKER_CAPTURE = $capturePath
        $env:ARABUT_WORKER_FAKE_ACTION = 'modify-existing'
        $env:ARABUT_WORKER_FAKE_TARGET = 'ignored.txt'

        & pwsh -NoProfile -File $wrapperPath -TaskFile $briefPath -Lane tests -ReadOnly *> $null

        $LASTEXITCODE | Should Be 71
    }
}
