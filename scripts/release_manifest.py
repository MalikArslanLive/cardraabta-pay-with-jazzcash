"""Explicit source manifest shared by the deterministic builder and verifier."""
from pathlib import Path

SLUG = 'cardraabta-pay-with-jazzcash'
VERSION = '1.0.0'
DOCUMENTS = ('LICENSE', 'NOTICE.txt', 'readme.txt')

RUNTIME = (
    SLUG + '.php', 'blocks.js', 'includes/security.php', 'includes/provider.php', 'includes/order-tools.php',
    'assets/admin.css', 'assets/checkout.css', 'assets/jazzcash.png', 'assets/order-check.js', 'assets/refund.js', 'assets/settings.js',
)

def source_files(root: Path):
    paths = [root / name for name in DOCUMENTS + RUNTIME]
    discovered = set(root.glob('*.php'))
    discovered.update(p for p in (root / 'includes').rglob('*') if p.is_file())
    discovered.update(p for p in (root / 'assets').rglob('*') if p.is_file())
    if discovered - set(paths):
        raise ValueError('Unlisted runtime files require manifest review')
    for path in paths:
        if not path.is_file() or path.is_symlink() or not path.resolve().is_relative_to(root.resolve()):
            raise ValueError('Missing or unsafe release file: ' + str(path))
    return sorted(paths)
