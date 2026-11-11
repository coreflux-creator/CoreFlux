"""Accept the one reviewed ended revision; preserve employee and payroll history."""
import fcntl,json,os,sys
from collections import Counter
from datetime import datetime,timezone
import activate_synthetic_lifecycle_pure as native

native.INPUT='cpa_42bd03b0440ec809c09ff23c2d410a6c23367c85f527376ff39a3f9be9221051'
native.STATE=native.PRIVATE/'synthetic-lifecycle-pure-offboarding-20261111.json'

def main():
 assert sys.argv[1:]==['--accept-original-synthetic-ending'] and os.geteuid()==0 and not native.STATE.exists()
 os.umask(0o077)
 lock=(native.PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 before=native.snapshot();old=before['assignment']
 assert old['status']=='active' and old['source_revision']==native.REVISION and before['employee']['status']=='active'
 with open(str(native.STATE)+'.before.private.json','x') as f:json.dump(before,f)
 report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':False,'phase':'login','production_changed':False,'real_payroll_payments_postings':False}
 with native.STATE.open('x') as f:json.dump(report,f)
 token=sid=None
 try:
  plan=json.loads((native.PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
  code,login=native.request('/auth/login',body={'email':'thunderhawk-core-pilot@example.invalid','password':plan['pure_password']})
  assert code==200 and login['organization']['id']==native.ORG and login['user']['id']==native.ACTOR
  token=login['token'];del plan,login
  code,security=native.request('/security/overview',token);assert code==200 and security['current_session_id'];sid=security['current_session_id']
  base='/assignments/core/'+native.INPUT
  code,review=native.request(base,token)
  assert code==200 and review['state']=='revision_ready' and review['assignment_status']=='active' and review['assignment_id']==native.ASSIGNMENT and review['missing_links']==[]
  packet=review['packet'];assert packet['source_status']=='ended' and packet['end_date']=='2026-11-10' and packet['previous_revision']==native.REVISION
  assert packet['placement']['number']=='PL-874' and packet['person']['number']=='P-368' and packet['start_date']=='2026-11-09'
  assert len(packet['terms'])==1 and packet['terms'][0]['end_date']=='2026-11-10'
  assert packet['terms'][0]['pay_rates']==old['core_assignment']['packet']['terms'][0]['pay_rates']
  assert packet['terms'][0]['bill_rates']==old['core_assignment']['packet']['terms'][0]['bill_rates']
  body={'review_hash':review['review_hash'],'confirmed':True}
  code,_=native.request(base+'/revise',token,{**body,'review_hash':'0'*64});assert code==409
  code,_=native.request(base+'/revise',token,{**body,'confirmed':False});assert code==422
  report['phase']='applying_revision';native.save(report)
  code,revised=native.request(base+'/revise',token,body)
  assert code==200 and revised['state']=='created' and revised['assignment_status']=='draft' and revised['assignment_id']==native.ASSIGNMENT
  code,replay=native.request(base+'/revise',token,body);assert code==200 and replay==revised
  report['revision_replayed_without_duplicate']=True;report['phase']='ending';native.save(report)
  body={'review_hash':revised['review_hash'],'confirmed':True}
  code,_=native.request(base+'/activate',token,{**body,'review_hash':'0'*64});assert code==409
  code,ended=native.request(base+'/activate',token,body)
  assert code==200 and ended['assignment_status']=='ended' and ended['assignment_id']==native.ASSIGNMENT and ended['accepted_revision']==packet['source_revision']
  report['phase']='ended';report['assignment_id']=ended['assignment_id'];report['accepted_revision']=ended['accepted_revision'];native.save(report)
  code,_=native.request(base+'/activate',token,body);assert code==409
  code,again=native.request(base+'/activate',token,{'review_hash':ended['review_hash'],'confirmed':True});assert code==200 and again==ended
  report.update(passed=True,phase='complete',assignment_status='ended',actual_end_date='2026-11-10',stale_reviews_refused=True,refreshed_end_replay_unchanged=True)
 except Exception as e:report.update(failure_class=type(e).__name__,failure_line=e.__traceback__.tb_lineno)
 finally:
  if token and sid:
   try:
    code,result=native.request('/security/sessions/revoke',token,{'session_id':sid});assert code==200 and result['status']=='revoked'
    report['synthetic_session_retired']=True
   except Exception as e:report.update(passed=False,cleanup_failure=type(e).__name__)
 try:
  after=native.snapshot();assert before['employee']==after['employee']
  assert set(before['collections'])==set(after['collections']);added={}
  for name,prior in before['collections'].items():
   fresh=after['collections'][name];assert prior['indexes']==fresh['indexes']
   oldrows,newrows=Counter(prior['rows']),Counter(fresh['rows']);assert not oldrows-newrows,name
   if newrows-oldrows:
    assert name in ['sessions','login_events'],name
    added[name]=sum((newrows-oldrows).values())
  assert added=={'sessions':1,'login_events':1},added
  report.update(employee_unchanged=True,other_original_records_unchanged=True,added_record_counts=added)
 except Exception as e:report.update(passed=False,preservation_failure=type(e).__name__,preservation_line=e.__traceback__.tb_lineno)
 native.save(report);print(json.dumps(report));return 0 if report['passed'] else 1

if __name__=='__main__':raise SystemExit(main())
