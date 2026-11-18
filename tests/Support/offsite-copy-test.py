import importlib.util
from pathlib import Path
import tempfile
import hashlib
import unittest

spec = importlib.util.spec_from_file_location('offsite', Path(__file__).resolve().parents[2] / 'deployment/security/offsite-copy.py')
offsite = importlib.util.module_from_spec(spec)
spec.loader.exec_module(offsite)

class OffsiteGuards(unittest.TestCase):
    def test_plaintext_wrong_checksum_and_symlinks_are_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / 'test.nuers'
            for b, sha in [(b'plain database', hashlib.sha256(b'plain database').hexdigest()), (b'NUERSBK1\nsynthetic', '0' * 64)]:
                p.write_bytes(b)
                with self.assertRaises(ValueError): offsite.verify_source(p, sha)
            b = b'NUERSBK1\nsynthetic'
            p.write_bytes(b)
            sha = hashlib.sha256(b).hexdigest()
            self.assertEqual(sha, offsite.verify_source(p, sha))
            link = Path(d) / 'link'
            link.symlink_to(p)
            with self.assertRaises(ValueError): offsite.verify_source(link, sha)

    def test_unprovisioned_local_and_unsafe_destinations_fail_closed(self):
        for dest in ['', '/tmp/local', 'https://host/bucket', 'backup:../escape', 'backup:path;command']:
            with self.assertRaises(ValueError): offsite.destination({'enabled': True, 'destination': dest}, 'archive.nuers')
        with self.assertRaises(ValueError): offsite.destination({'enabled': False, 'destination': 'backup:bucket'}, 'archive.nuers')
        self.assertEqual('backup:bucket/nuers/archive.nuers', offsite.destination({'enabled': True, 'destination': 'backup:bucket/nuers'}, 'archive.nuers'))

if __name__ == '__main__': unittest.main()
