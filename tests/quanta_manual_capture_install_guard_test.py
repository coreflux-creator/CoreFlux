"""Accept only the reviewed empty schema and one startup coordinator increment."""
import ast
from copy import deepcopy
from pathlib import Path
import unittest

source=(Path(__file__).resolve().parents[1]/'deploy/install_thunderhawk_manual_capture.py').read_text()
tree=ast.parse(source)
fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='verify_preservation')
scope={'GUARD':'coreflux_commit_fences','GUARD_ID':'core-time-writers-v1'}
exec(compile(ast.Module(body=[fn],type_ignores=[]),'install-guard','exec'),scope)
verify=scope['verify_preservation']

class Preservation(unittest.TestCase):
 def setUp(self):
  self.before={'time_entries':{'count':12,'sha256':'before','indexes':[]},
   'coreflux_commit_fences':{'count':1,'sha256':'guard-before','indexes':[{'name':'_id_'}],'options':{}}}
  self.after=deepcopy(self.before)
  self.after['coreflux_commit_fences']['sha256']='guard-after'
  self.after['manual_time_receipts']={'count':0,'options':{},'indexes':[{'name':'_id_'},{'name':'tenant_id_1_actor_id_1_created_at_-1'}]}
  self.old={'_id':'core-time-writers-v1','revision':2};self.new={**self.old,'revision':3}
 def test_empty_receipt_schema_and_single_startup(self):
  self.assertEqual(verify(self.before,self.after,self.old,self.new),['manual_time_receipts'])
 def test_changed_business_record_refused(self):
  self.after['time_entries']['sha256']='changed'
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_missing_original_collection_refused(self):
  del self.after['time_entries']
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_unreviewed_new_schema_refused(self):
  self.after['unexpected']={'count':0}
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_new_receipt_records_refuse_rollback(self):
  self.after['manual_time_receipts']['count']=1
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new,rollback=True)
 def test_later_coordinated_write_refuses_rollback(self):
  self.new['revision']=4
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new,rollback=True)
 def test_partial_install_before_startup_can_restore(self):
  self.assertEqual(verify(self.before,self.before,self.old,self.old,rollback=True),[])
 def test_success_requires_startup(self):
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.old)
 def test_success_requires_receipt_schema(self):
  del self.after['manual_time_receipts']
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_receipts_must_not_expire(self):
  self.after['manual_time_receipts']['indexes'][1]['expireAfterSeconds']=86400
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
 def test_changed_guard_fields_refused(self):
  self.new['extra']='changed'
  with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)

if __name__=='__main__':unittest.main()
