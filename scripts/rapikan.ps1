<#
  rapikan.ps1 - merapikan repo CINÉLOG dari berkas yang tidak dipakai.

  Jalankan dari ROOT proyek (folder yang berisi docker-compose.yml).

    .\scripts\rapikan.ps1           # SIMULASI: hanya menampilkan apa yang akan dihapus
    .\scripts\rapikan.ps1 -Hapus    # benar-benar menghapus

  Berkas yang sudah di-commit dihapus lewat `git rm`, jadi masih bisa dikembalikan
  (git restore). Berkas sementara (tidak di-commit) dihapus langsung.
  Tidak menyentuh: .env, data/, database/, ontology/, pipeline/*.py, vendor, .venv.
#>
param([switch]$Hapus)

if (-not (Test-Path 'docker-compose.yml')) {
    Write-Host 'Jalankan skrip ini dari ROOT proyek (folder yang berisi docker-compose.yml).' -ForegroundColor Red
    exit 1
}

# ---- Berkas/folder yang dibuang beserta alasannya ----
$daftar = @(
    @{ P = 'docker\fuseki\Dockerfile.bak';             Alasan = 'cadangan Dockerfile lama' },
    @{ P = 'pipeline\__pycache__';                      Alasan = 'bytecode Python (otomatis dibuat ulang)' },
    @{ P = 'web\resources\views\welcome.blade.php';     Alasan = 'halaman sambutan bawaan Laravel (72 KB), tidak dipakai' },
    @{ P = 'web\tests\Feature\ExampleTest.php';         Alasan = 'tes contoh bawaan; GET / butuh tabel movies, sedangkan tes memakai sqlite kosong' },
    @{ P = 'web\tests\Unit\ExampleTest.php';            Alasan = 'tes contoh bawaan' },
    @{ P = 'web\database\seeders\DatabaseSeeder.php';   Alasan = 'memanggil User::factory(), padahal User tanpa HasFactory dan skema dari SQL' },
    @{ P = 'web\database\factories\UserFactory.php';    Alasan = 'factory bawaan tidak cocok dengan tabel users kita (tanpa kolom role)' },
    @{ P = 'web\package.json';                          Alasan = 'Node/Vite tidak dipakai (Bootstrap lewat CDN)' },
    @{ P = 'web\vite.config.js';                        Alasan = 'konfigurasi Vite, tidak dipakai' },
    @{ P = 'web\.npmrc';                                Alasan = 'konfigurasi npm, tidak dipakai' },
    @{ P = 'web\resources\css\app.css';                 Alasan = 'aset Vite, tidak dipakai' },
    @{ P = 'web\resources\js\app.js';                   Alasan = 'aset Vite, tidak dipakai' },
    @{ P = 'web\AGENTS.md';                             Alasan = 'instruksi alat AI bawaan (menyuruh memasang paket), identik dengan CLAUDE.md' },
    @{ P = 'web\CLAUDE.md';                             Alasan = 'idem' },
    @{ P = 'web\README.md';                             Alasan = 'README bawaan Laravel; diganti README.md di root' }
)

# ---- Berkas sementara hasil jalan (isi folder dibuang, .gitignore dipertahankan) ----
$sementara = @(
    @{ P = 'web\storage\framework\sessions';   Alasan = 'berkas sesi lama' },
    @{ P = 'web\storage\framework\views';      Alasan = 'cache Blade hasil kompilasi' },
    @{ P = 'web\storage\framework\cache\data'; Alasan = 'cache aplikasi' },
    @{ P = 'web\storage\logs';                 Alasan = 'log lama (isinya galat APP_KEY yang sudah teratasi)' }
)

function Tampil($tipe, $path, $alasan) {
    Write-Host ("  [{0}] {1}" -f $tipe, $path) -NoNewline
    Write-Host ("   - {0}" -f $alasan) -ForegroundColor DarkGray
}

Write-Host ''
Write-Host ($(if ($Hapus) { '== MENGHAPUS ==' } else { '== SIMULASI (belum ada yang dihapus) ==' })) -ForegroundColor Cyan

$jumlah = 0
foreach ($i in $daftar) {
    if (-not (Test-Path $i.P)) { continue }
    $jumlah++
    Tampil 'buang' $i.P $i.Alasan
    if ($Hapus) {
        $terlacak = git ls-files -- $i.P
        if ($terlacak) { git rm -r -f -q -- $i.P }
        if (Test-Path $i.P) { Remove-Item -LiteralPath $i.P -Recurse -Force }
    }
}

foreach ($i in $sementara) {
    if (-not (Test-Path $i.P)) { continue }
    $isi = Get-ChildItem -LiteralPath $i.P -Recurse -File -Force | Where-Object { $_.Name -ne '.gitignore' }
    if (-not $isi) { continue }
    $jumlah++
    Tampil 'bersih' ("{0}  ({1} berkas)" -f $i.P, @($isi).Count) $i.Alasan
    if ($Hapus) { $isi | Remove-Item -Force }
}

Write-Host ''
if ($jumlah -eq 0) {
    Write-Host 'Tidak ada yang perlu dirapikan.' -ForegroundColor Green
} elseif (-not $Hapus) {
    Write-Host "$jumlah item akan dirapikan. Jika daftar di atas sudah benar:  .\scripts\rapikan.ps1 -Hapus" -ForegroundColor Yellow
} else {
    Write-Host "Selesai: $jumlah item dirapikan. Periksa dengan:  git status" -ForegroundColor Green
}
