#!/usr/bin/env python3
"""Kernel-enforced unused-module restriction. Vendor advisories remain open."""
import pathlib,subprocess,json,os,sys
modules=['ksmbd','nfsd','ntfs3','algif_skcipher','binder_linux','amdgpu']
parameter='module_blacklist='+','.join(modules)
path=pathlib.Path('/etc/default/grub.d/99-nuers-unused-modules.cfg')
assert os.geteuid()==0
assert subprocess.check_output(['uname','-r'],text=True).strip()=='7.0.0-1017-aws'
assert all(not pathlib.Path('/sys/module/'+m).exists()for m in modules),'An active module must not be disabled'
if '--verify' in sys.argv:
 assert parameter in pathlib.Path('/proc/cmdline').read_text().split(),'Reboot with tested restriction first'
 for m in modules:
  file=subprocess.check_output(['modinfo','-n',m],text=True).strip()
  result=subprocess.run(['insmod',file],text=True,capture_output=True)
  assert result.returncode!=0 and 'Operation not permitted' in result.stderr,(m,result.stderr)
  assert not pathlib.Path('/sys/module/'+m).exists(),m
 print(json.dumps({'kernel_blacklist_verified':True,'direct_insmod_attempts_denied':6,'modules':modules,'kernel_version':'7.0.0-1017-aws','vendor_cves_closed':False}))
else:
 assert '--prepare' in sys.argv
 body='# NUERS compensating control. Reboot required; vendor advisories remain tracked.\nGRUB_CMDLINE_LINUX="$GRUB_CMDLINE_LINUX '+parameter+'"\n'
 assert not path.exists() or path.read_text()==body,'Unrelated configuration must not be overwritten'
 assert len([x for x in pathlib.Path('/proc/cmdline').read_text().split()if x.startswith('module_blacklist=') and x!=parameter])==0
 backup=pathlib.Path('/var/backups/nuers-assurance-20261116');backup.mkdir(mode=0o700,exist_ok=True)
 if not (backup/'cmdline-before.txt').exists():(backup/'cmdline-before.txt').write_text(pathlib.Path('/proc/cmdline').read_text())
 if not (backup/'grub-before.cfg').exists():__import__('shutil').copy2('/boot/grub/grub.cfg',backup/'grub-before.cfg')
 path.write_text(body);path.chmod(0o644)
 subprocess.run(['update-grub'],check=True,capture_output=True)
 assert parameter in pathlib.Path('/boot/grub/grub.cfg').read_text()
 print(json.dumps({'prepared':True,'reboot_required':True,'boot_id':pathlib.Path('/proc/sys/kernel/random/boot_id').read_text().strip()}))
