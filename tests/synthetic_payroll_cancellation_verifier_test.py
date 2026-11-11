"""Ensure cancellation evidence cannot pass with retained claims or unrelated drift."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path

path=Path(__file__).resolve().parents[1]/'deploy/inspect_synthetic_payroll_cancellation.py'
spec=importlib.util.spec_from_file_location('cancellation_verifier',path)
v=importlib.util.module_from_spec(spec); spec.loader.exec_module(v)
before={'checked_at':'2026-11-11T20:00:00+00:00','container':{'id':'original','config_hash':'original'},
 'runtime':{'server.py':'original'},'key_id':'test-key','collections':{'untouched':{'rows':['original'],'indexes':'original'}},
 'authority':{'state':'allocated','authority':{'input_id':v.INPUT}},
 'run':{'status':'draft','core_allocation_ready':True,'core_execution_hold':True,
        'core_allocation':{'request_hash':'a'*64},'lines':[{'gross':'50.00'}]},
 'claims':[{'source':'core'},{'source':'quanta'}]}
after=copy.deepcopy(before); after['claims']=[]
fence='cpf_'+hashlib.sha256(json.dumps([v.ORG,v.AUTHORITY,v.RUN,'a'*64,'cancelled'],separators=(',',':')).encode()).hexdigest()
after['authority'].update(state='released',cancellation_started_at='2026-11-11T20:01:00Z',
 cancelled_by='Core key test-key',cancel_fence=fence,released_at='2026-11-11T20:01:01Z')
after['run'].update(status='void',core_cancelled=True,core_cancel_fence=fence,core_allocation_ready=False,
 voided_at='2026-11-11T20:01:00Z',voided_by='Core key test-key')
assert v.verify(before,after)['passed']; count=1

def rejected(label,change):
 global count
 altered=copy.deepcopy(after); change(altered)
 try:v.verify(before,altered)
 except (AssertionError,KeyError):
  count+=1; print('PASS refuses '+label); return
 raise AssertionError('Verifier accepted '+label)

rejected('a retained source claim',lambda x:x['claims'].append(before['claims'][0]))
rejected('a changed financial line',lambda x:x['run']['lines'][0].update(gross='51.00'))
rejected('an altered original input',lambda x:x['authority']['authority'].update(input_id='other'))
rejected('a wrong permanent fence',lambda x:x['run'].update(core_cancel_fence='other'))
rejected('missing cancellation evidence',lambda x:x['authority'].pop('cancel_fence'))
rejected('a reopened run',lambda x:x['run'].update(status='draft'))
rejected('a run still ready for allocation',lambda x:x['run'].update(core_allocation_ready=True))
rejected('a removed execution hold',lambda x:x['run'].update(core_execution_hold=False))
rejected('an unknown cancellation actor',lambda x:(x['authority'].update(cancelled_by='other'),x['run'].update(voided_by='other')))
rejected('another container',lambda x:x['container'].update(id='other'))
rejected('changed runtime code',lambda x:x['runtime'].update({'server.py':'other'}))
rejected('an unrelated record change',lambda x:x['collections']['untouched'].update(rows=['other']))
rejected('changed database indexes',lambda x:x['collections']['untouched'].update(indexes='other'))
rejected('a different original integration key',lambda x:x.update(key_id='other'))
print(str(count)+' cancellation evidence checks passed.')
