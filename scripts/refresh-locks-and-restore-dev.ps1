param(
    [string]$CommitMessage = "chore(deps): refresh composer.lock",
    [switch]$Push,
    [switch]$DryRun,
    [string[]]$OnlyRepos
)
$ErrorActionPreference = "Stop"
$repos = @(
    "D:\laragon\www\amazon-api-anibal",
    "D:\laragon\www\amazon-hub-driver",
    "D:\laragon\www\api-client-skeleton",
    "D:\laragon\www\api-driver-core",
    "D:\laragon\www\apis-hub",
    "D:\laragon\www\apis-hub-api",
    "D:\laragon\www\apis-hub-facade",
    "D:\laragon\www\bigcommerce-hub-driver",
    "D:\laragon\www\facebook-graph-api",
    "D:\laragon\www\google-api-anibal",
    "D:\laragon\www\google-hub-driver",
    "D:\laragon\www\klaviyo-api-anibal",
    "D:\laragon\www\klaviyo-hub-driver",
    "D:\laragon\www\linkedin-hub-driver",
    "D:\laragon\www\mailchimp-api-anibal",
    "D:\laragon\www\meta-hub-driver",
    "D:\laragon\www\netsuite-api-anibal",
    "D:\laragon\www\netsuite-hub-driver",
    "D:\laragon\www\pinterest-hub-driver",
    "D:\laragon\www\shipstation-api-anibal",
    "D:\laragon\www\shopify-api-anibal",
    "D:\laragon\www\shopify-hub-driver",
    "D:\laragon\www\tiktok-hub-driver",
    "D:\laragon\www\triple-whale-api-anibal",
    "D:\laragon\www\triple-whale-hub-driver",
    "D:\laragon\www\x-hub-driver"
)
if ($OnlyRepos -and $OnlyRepos.Count -gt 0) {
    $repos = $repos | Where-Object { $OnlyRepos -contains (Split-Path $_ -Leaf) }
}
function Write-Utf8NoBomJson {
    param(
        [string]$Path,
        [object]$Data
    )
    $json = $Data | ConvertTo-Json -Depth 100
    $encoding = New-Object System.Text.UTF8Encoding($false)
    $writer = New-Object System.IO.StreamWriter($Path, $false, $encoding)
    try {
        $writer.Write($json)
        $writer.Write("`n")
    }
    finally {
        $writer.Dispose()
    }
}
function Get-ComposerData {
    param([string]$Path)
    return Get-Content $Path -Raw | ConvertFrom-Json
}
function Remove-PathRepositories {
    param([string]$Path)
    $data = Get-ComposerData $Path
    if ($data.repositories -is [System.Array]) {
        $data.repositories = @($data.repositories | Where-Object { $_.type -ne 'path' })
    }
    Write-Utf8NoBomJson -Path $Path -Data $data
}
function Has-LocalDependencies {
    param([string]$Path)
    $data = Get-ComposerData $Path
    $names = @()
    if ($data.require) {
        $names += $data.require.PSObject.Properties.Name
    }
    if ($data.'require-dev') {
        $names += $data.'require-dev'.PSObject.Properties.Name
    }
    return [bool]($names | Where-Object { $_ -like 'anibalealvarezs/*' } | Select-Object -First 1)
}
function Get-TailText {
    param(
        [string]$Text,
        [int]$Last = 8
    )
    if (-not $Text) {
        return ''
    }
    return (($Text -split "`r?`n") | Where-Object { $_ -ne '' } | Select-Object -Last $Last) -join ' | '
}
$composerCmd = (Get-Command composer.bat -ErrorAction SilentlyContinue).Source
if (-not $composerCmd) {
    $composerCmd = (Get-Command composer -ErrorAction SilentlyContinue).Source
}
if (-not $composerCmd) {
    throw "composer command not found in PATH"
}

function Invoke-ComposerSafe {
    param(
        [string]$WorkingDirectory,
        [string[]]$Arguments
    )

    $previousPreference = $global:ErrorActionPreference
    try {
        $global:ErrorActionPreference = 'Continue'
        $output = (& $composerCmd --working-dir $WorkingDirectory @Arguments 2>&1 | Out-String)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $global:ErrorActionPreference = $previousPreference
    }

    return [pscustomobject]@{
        Output = $output
        ExitCode = $exitCode
    }
}

$results = @()
foreach ($repo in $repos) {
    $name = Split-Path $repo -Leaf
    $composerJsonPath = Join-Path $repo "composer.json"
    $composerLockPath = Join-Path $repo "composer.lock"
    $commitState = $null
    if (-not (Test-Path $composerJsonPath) -or -not (Test-Path $composerLockPath)) {
        $results += [pscustomobject]@{ Repo = $name; Status = 'skip'; Detail = 'missing composer.json/composer.lock' }
        continue
    }
    if ($DryRun) {
        $results += [pscustomobject]@{ Repo = $name; Status = 'dry-run'; Detail = 'would update lock, commit, restore local symlinks' }
        continue
    }
    $originalComposerBytes = [System.IO.File]::ReadAllBytes($composerJsonPath)
    try {
        & git -C $repo update-index --no-assume-unchanged composer.lock | Out-Null
        Remove-PathRepositories -Path $composerJsonPath
        $releaseResult = Invoke-ComposerSafe -WorkingDirectory $repo -Arguments @('update', '--no-interaction', '--ignore-platform-reqs', '--prefer-dist', '--no-scripts')
        if ($releaseResult.ExitCode -ne 0) {
            throw "composer update (release lock) failed: $(Get-TailText -Text $releaseResult.Output)"
        }
        & git -C $repo add composer.lock
        & git -C $repo diff --cached --ignore-cr-at-eol --quiet -- composer.lock
        $changed = ($LASTEXITCODE -ne 0)
        if ($changed) {
            & git -C $repo commit -m $CommitMessage -- composer.lock | Out-Null
            if ($LASTEXITCODE -ne 0) {
                throw 'git commit failed'
            }
            if ($Push) {
                & git -C $repo push | Out-Null
                if ($LASTEXITCODE -ne 0) {
                    throw 'git push failed'
                }
            }
            $commitState = 'committed'
        }
        else {
            & git -C $repo reset -- composer.lock | Out-Null
            $commitState = 'no-lock-change'
        }
    }
    catch {
        $results += [pscustomobject]@{ Repo = $name; Status = 'fail'; Detail = $_.Exception.Message }
    }
    finally {
        [System.IO.File]::WriteAllBytes($composerJsonPath, $originalComposerBytes)
        if (Has-LocalDependencies -Path $composerJsonPath) {
            $vendorLocal = Join-Path $repo 'vendor\anibalealvarezs'
            if (Test-Path $vendorLocal) {
                Remove-Item $vendorLocal -Recurse -Force
            }
            $devResult = Invoke-ComposerSafe -WorkingDirectory $repo -Arguments @('update', 'anibalealvarezs/*', '-W', '--ignore-platform-reqs', '--no-interaction', '--no-scripts')
            if ($devResult.ExitCode -ne 0) {
                $results += [pscustomobject]@{ Repo = $name; Status = 'warn'; Detail = "failed to restore local symlinks: $(Get-TailText -Text $devResult.Output)" }
            }
        }
        & git -C $repo update-index --assume-unchanged composer.json | Out-Null
        & git -C $repo update-index --assume-unchanged composer.lock | Out-Null
    }
    if ($commitState) {
        $results += [pscustomobject]@{ Repo = $name; Status = 'ok'; Detail = $commitState }
    }
}
$results | Sort-Object Repo | Format-Table -AutoSize | Out-String | Write-Output
