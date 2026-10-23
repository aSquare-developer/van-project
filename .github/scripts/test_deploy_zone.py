"""Exercise deployment filesystem safety and recovery without a remote server."""

import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest


SCRIPT = Path(__file__).with_name('deploy-zone.sh').resolve()
RELEASE_ID = 'a' * 40 + '-123-1'


class ZoneDeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='van-zone-test-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / 'van'
        self.root.mkdir()
        (self.root / '.van-project').write_text('asquare/van-project\n')
        (self.root / '.env').write_text('server-only-configuration\n')
        (self.root / '.incoming').mkdir()
        (self.root / 'storage/logs').mkdir(parents=True)
        (self.root / 'storage/logs/existing.log').write_text('keep user data\n')
        self.php = self.base / 'php84-cli'
        self.php.write_text('''#!/usr/bin/env bash
set -eu
if [[ "${1:-}" == -r ]]; then
    exit "${FAKE_VERSION_STATUS:-0}"
fi
if [[ $# == 0 ]]; then
    while IFS= read -r line; do :; done
    exit "${FAKE_CONFIG_STATUS:-0}"
fi
printf '%s\\n' "$*" >> "$FAKE_LOG"
if [[ "${2:-}" == "${FAKE_FAIL_ON:-never}" ]]; then
    exit 42
fi
''')
        self.php.chmod(0o700)
        self.log = self.base / 'php-calls.log'
        self.payload = self.base / 'payload'
        for directory in ['vendor', 'public/build', 'storage', 'bootstrap/cache']:
            (self.payload / directory).mkdir(parents=True, exist_ok=True)
        (self.payload / 'artisan').write_text('fixture')
        (self.payload / 'vendor/autoload.php').write_text('fixture')
        (self.payload / 'public/build/manifest.json').write_text('{}')
        self.package()

    def package(self):
        with tarfile.open(self.root / '.incoming' / (RELEASE_ID + '.tar.gz'), 'w:gz') as archive:
            archive.add(self.payload, arcname='.')

    def deploy(self, **environment):
        return subprocess.run(
            ['bash', str(SCRIPT), str(self.root), RELEASE_ID, str(self.php)],
            env={**os.environ, 'FAKE_LOG': str(self.log), **environment},
            capture_output=True, text=True, timeout=10,
        )

    def existing_release(self):
        old = self.root / 'releases/previous'
        (old / 'public').mkdir(parents=True)
        (self.root / 'current').symlink_to('releases/previous')
        (self.root / 'public').symlink_to('current/public')

    def test_success_preserves_configuration_and_storage_and_switches_release(self):
        self.existing_release()
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(os.readlink(self.root / 'current'), 'releases/' + RELEASE_ID)
        self.assertEqual(os.readlink(self.root / 'public'), 'current/public')
        self.assertEqual((self.root / '.env').read_text(), 'server-only-configuration\n')
        self.assertEqual((self.root / 'storage/logs/existing.log').read_text(), 'keep user data\n')
        release = self.root / 'current'
        self.assertEqual((release / '.env').resolve(), self.root / '.env')
        self.assertEqual((release / 'storage').resolve(), self.root / 'storage')
        self.assertEqual((self.root / '.env').stat().st_mode & 0o777, 0o600)

    def test_first_deployment_creates_the_public_entry_point(self):
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(os.readlink(self.root / 'public'), 'current/public')

    def test_private_archive_root_is_made_traversable_for_the_web_server(self):
        self.payload.chmod(0o700)
        self.package()
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.root / 'current').stat().st_mode & 0o777, 0o755)
        self.assertEqual((self.root / '.env').stat().st_mode & 0o777, 0o600)

    def test_failed_migration_keeps_previous_release_and_exits_maintenance(self):
        self.existing_release()
        result = self.deploy(FAKE_FAIL_ON='migrate')
        self.assertEqual(result.returncode, 42, result.stderr)
        self.assertEqual(os.readlink(self.root / 'current'), 'releases/previous')
        self.assertIn('artisan up', self.log.read_text())
        self.assertEqual((self.root / '.env').read_text(), 'server-only-configuration\n')

    def test_missing_marker_refuses_to_touch_the_target(self):
        (self.root / '.van-project').unlink()
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / 'releases').exists())

    def test_wrong_project_marker_is_rejected(self):
        (self.root / '.van-project').write_text('another/project')
        self.assertNotEqual(self.deploy().returncode, 0)
        self.assertFalse((self.root / 'releases').exists())

    def test_existing_public_directory_is_preserved(self):
        (self.root / 'public').mkdir()
        (self.root / 'public/existing.txt').write_text('keep')
        self.assertNotEqual(self.deploy().returncode, 0)
        self.assertEqual((self.root / 'public/existing.txt').read_text(), 'keep')

    def test_symlinked_storage_is_rejected(self):
        (self.root / 'storage').rename(self.base / 'outside-storage')
        (self.root / 'storage').symlink_to(self.base / 'outside-storage')
        self.assertNotEqual(self.deploy().returncode, 0)
        self.assertEqual((self.base / 'outside-storage/logs/existing.log').read_text(), 'keep user data\n')

    def test_archive_containing_env_is_rejected(self):
        (self.payload / '.env').write_text('must never replace server configuration')
        self.package()
        self.assertNotEqual(self.deploy().returncode, 0)
        self.assertFalse((self.root / 'current').exists())
        self.assertEqual((self.root / '.env').read_text(), 'server-only-configuration\n')

    def test_invalid_production_configuration_does_not_enter_maintenance(self):
        self.existing_release()
        result = self.deploy(FAKE_CONFIG_STATUS='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.log.exists())
        self.assertEqual(os.readlink(self.root / 'current'), 'releases/previous')

    def test_unsupported_php_is_rejected_before_extracting(self):
        self.assertNotEqual(self.deploy(FAKE_VERSION_STATUS='1').returncode, 0)
        self.assertFalse((self.root / 'releases').exists())


if __name__ == '__main__':
    unittest.main()
