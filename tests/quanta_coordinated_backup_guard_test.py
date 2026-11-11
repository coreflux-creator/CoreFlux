"""One expected startup write must not mask other coordinator mutations."""
import ast
from copy import deepcopy
from pathlib import Path
import unittest

source=(Path(__file__).resolve().parents[1]/'deploy/backup_thunderhawk_quanta_storage.py').read_text()
tree=ast.parse(source)
fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='verify_startup_guard')
scope={'GUARD':'coreflux_commit_fences','GUARD_ID':'core-time-writers-v1'}
exec(compile(ast.Module(body=[fn],type_ignores=[]),'guard','exec'),scope)
verify=scope['verify_startup_guard']

class Guard(unittest.TestCase):
 def setUp(self):
  self.before={'coreflux_commit_fences':{'count':1,'indexes':[{'name':'_id_'}],'options':{}}}
  self.after=deepcopy(self.before)
  self.old={'_id':'core-time-writers-v1','revision':1}
  self.new={**self.old,'revision':2}
 def test_one_startup_increment(self):verify(self.before,self.after,self.old,self.new)
 def test_later_business_write_refused(self):
  self.new['revision']=3
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_missing_startup_refused(self):
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.old)
 def test_extra_guard_field_refused(self):
  self.new['unexpected']=True
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_extra_guard_record_refused(self):
  self.after['coreflux_commit_fences']['count']=2
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_changed_guard_index_refused(self):
  self.after['coreflux_commit_fences']['indexes']=[{'name':'another'}]
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_missing_original_guard_refused(self):
  with self.assertRaises(AssertionError):verify(self.before,self.after,None,self.new)

if __name__=='__main__':unittest.main()
