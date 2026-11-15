import os, sys, threading, time, json, tempfile
HERE=os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0,os.path.dirname(os.path.dirname(HERE))); sys.path.insert(0,os.path.dirname(HERE))
os.environ['JARVIS_AGENT_HOME']=tempfile.mkdtemp()
from conftest import FakeServer
from jarvis_agent import gui
fs=FakeServer()
fs.desktop=os.environ.get("E2E_OLD_SERVER")!="1"
root=tempfile.mkdtemp(); open(root+'/a.txt','w').write('hello')
fs.queue.append({"id":"act"+"0"*19+"001","kind":"read_file","risk":"low","params":{"path":root+"/a.txt"}})
from jarvis_agent import crashreport
try:
    raise ValueError('sample crash Bearer '+'Q'*30)
except ValueError as _e:
    crashreport.record(type(_e),_e,_e.__traceback__,'e2e')
st=gui.State(allow_http=True); srv=gui.bind(st,"E2ETOKEN",0)
print(json.dumps({"url":f"http://127.0.0.1:{srv.server_address[1]}/#E2ETOKEN","server":fs.url,"code":fs.code,"root":root}),flush=True)
srv.serve_forever()
