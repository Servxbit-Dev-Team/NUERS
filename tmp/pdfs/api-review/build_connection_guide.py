"""Build the maintained merchant connection guide at the legacy download filename."""
from pathlib import Path
import runpy, shutil
root = Path(__file__).resolve().parents[3]
runpy.run_path(str(root / 'tmp/pdfs/merchant-connection-final/build.py'), run_name='__main__')
shutil.copyfile(root / 'output/pdf/NUERS-Merchant-API-Connection-Guide.pdf', root / 'output/pdf/NUERS-API-Connection-Guide.pdf')
