"""A source-read patch may only advance the existing startup guard once."""
import ast
from copy import deepcopy
from pathlib import Path
import unittest

source=(Path(__file__).resolve().parents[1]/'deploy/install_thunderhawk_source_snapshot.py').read_text()
tree=ast.parse(source)
fn=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='verify_preservation')
scope={'GUARD':'coreflux_commit_fences','GUARD_ID':'core-time-writers-v1'}
exec(compile(ast.Module(body=[fn],type_ignores=[]),'snapshot-guard','exec'),scope)
verify=scope['verify_preservation']

class Preservation(unittest.TestCase):
    def setUp(self):
        self.before={'time_entries':{'count':12,'sha256':'original','indexes':[],'options':{}},
            'manual_time_receipts':{'count':0,'sha256':'empty','indexes':[],'options':{}},
            'coreflux_commit_fences':{'count':1,'sha256':'guard-before','indexes':[{'name':'_id_'}],'options':{}}}
        self.after=deepcopy(self.before);self.after['coreflux_commit_fences']['sha256']='guard-after'
        self.old={'_id':'core-time-writers-v1','revision':4};self.new={**self.old,'revision':5}
    def test_only_one_startup_increment_allowed(self):
        self.assertEqual(verify(self.before,self.after,self.old,self.new),[])
    def test_changed_business_record_refused(self):
        self.after['time_entries']['sha256']='changed'
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
    def test_collection_added_refused(self):
        self.after['unexpected']={'count':0}
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
    def test_collection_removed_refused(self):
        del self.after['manual_time_receipts']
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
    def test_new_receipt_refuses_rollback(self):
        self.after['manual_time_receipts']['count']=1
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new,rollback=True)
    def test_later_write_refuses_rollback(self):
        self.new['revision']=6
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new,rollback=True)
    def test_pre_start_failure_can_restore(self):
        self.assertEqual(verify(self.before,self.before,self.old,self.old,rollback=True),[])
    def test_success_requires_startup(self):
        with self.assertRaises(AssertionError):verify(self.before,self.before,self.old,self.old)
    def test_index_change_refused(self):
        self.after['time_entries']['indexes']=[{'name':'new-index'}]
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
    def test_guard_shape_change_refused(self):
        self.new['extra']=True
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)
    def test_extra_guard_row_refused(self):
        self.after['coreflux_commit_fences']['count']=2
        with self.assertRaises(AssertionError):verify(self.before,self.after,self.old,self.new)

if __name__=='__main__':unittest.main()
