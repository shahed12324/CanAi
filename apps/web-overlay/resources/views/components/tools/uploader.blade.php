@props(['accept' => '', 'min' => 1, 'max' => 1, 'labels' => null, 'camera' => false, 'name' => 'files[]', 'id' => 'up'])
{{-- Upload box. 1–2 files with labels (e.g. front/back) → one slot per file. Otherwise a drag-and-drop zone with previews, order and remove. --}}
@php
    $slots = $labels ?? ($max === 2 ? ($min === 2 ? ['Front side', 'Back side'] : ['Front side', 'Back side (optional)']) : null);
@endphp
@if ($slots && $max <= 3)
    <div class="grid gap-3 sm:grid-cols-{{ count($slots) }}" data-slots="{{ $id }}">
        @foreach ($slots as $n => $label)
            <div class="rounded-xl border-2 border-dashed jarvis-upload border-indigo-200 p-3 text-center transition hover:border-indigo-400" data-slot>
                <p class="mb-2 text-sm font-semibold text-gray-700">{{ $label }}@if ($n < $min) <span class="text-red-500">*</span>@endif</p>
                <label class="grid cursor-pointer place-items-center gap-1">
                    <span class="grid h-36 w-full place-items-center overflow-hidden rounded-lg bg-white text-xs text-gray-500 ring-1 ring-gray-200" data-preview>
                        <span><span class="block text-3xl">📤</span>Click or drop an image<br><span class="text-[11px] text-gray-400">upload your {{ strtolower(rtrim(preg_replace('/\(.*\)/', '', $label))) }} image</span></span>
                    </span>
                    <input type="file" name="{{ $name }}" accept="{{ $accept }}" @if ($n < $min) required @endif @if ($camera) capture="environment" @endif class="sr-only">
                    <span class="text-xs text-indigo-600 underline" data-filename>Choose file</span>
                </label>
            </div>
        @endforeach
    </div>
@else
    <div data-multi="{{ $id }}">
        <label class="grid cursor-pointer place-items-center gap-1 rounded-xl border-2 border-dashed jarvis-upload border-indigo-200 p-6 text-center transition hover:border-indigo-400" data-drop>
            <span class="text-4xl">📤</span>
            <span class="text-sm font-semibold text-gray-700">Upload your file{{ $max > 1 ? 's' : '' }}</span>
            <span class="text-xs text-gray-500">Drag and drop here, or click to choose{{ $max > 1 ? ' (up to '.$max.' files, 25 MB each)' : ' (25 MB max)' }}</span>
            <input type="file" accept="{{ $accept }}" @if ($max > 1) multiple @endif @if ($camera) capture="user" @endif class="sr-only" data-picker>
        </label>
        <input type="file" name="{{ $name }}" multiple class="hidden" data-real @if ($min > 0) data-min="{{ $min }}" @endif>
        <ul class="mt-2 grid gap-2" data-list></ul>
    </div>
@endif
<script>
(function(){
  var root=document.currentScript.previousElementSibling; if(!root) return;
  function thumb(f,box){box.innerHTML='';if(f&&f.type&&f.type.indexOf('image/')===0){var i=new Image();i.src=URL.createObjectURL(f);i.className='max-h-36 object-contain';box.appendChild(i);}else{box.textContent='📄 '+(f?f.name:'');}}
  // slots
  root.querySelectorAll('[data-slot]').forEach(function(s){
    var inp=s.querySelector('input'),pv=s.querySelector('[data-preview]'),fn=s.querySelector('[data-filename]'),def=pv.innerHTML;
    inp.addEventListener('change',function(){var f=inp.files[0];if(f){thumb(f,pv);fn.textContent=f.name;}else{pv.innerHTML=def;fn.textContent='Choose file';}});
    ['dragover','dragenter'].forEach(function(e){s.addEventListener(e,function(ev){ev.preventDefault();s.classList.add('border-indigo-500');});});
    ['dragleave','drop'].forEach(function(e){s.addEventListener(e,function(){s.classList.remove('border-indigo-500');});});
    s.addEventListener('drop',function(ev){ev.preventDefault();if(ev.dataTransfer.files.length){inp.files=ev.dataTransfer.files;inp.dispatchEvent(new Event('change'));}});
  });
  // multi
  var real=root.querySelector('[data-real]'); if(!real) return;
  var picker=root.querySelector('[data-picker]'),drop=root.querySelector('[data-drop]'),list=root.querySelector('[data-list]'),files=[],max={{ (int) $max }};
  function sync(){var dt=new DataTransfer();files.forEach(function(f){dt.items.add(f);});real.files=dt.files;render();}
  function add(fl){Array.prototype.forEach.call(fl,function(f){if(files.length<max&&f.size<=25*1024*1024)files.push(f);});if(max===1&&fl.length)files=[fl[0]];sync();}
  function render(){list.innerHTML='';files.forEach(function(f,i){var li=document.createElement('li');li.className='flex items-center gap-3 rounded-lg bg-white p-2 text-xs shadow-sm ring-1 ring-gray-200';
    var tb=document.createElement('span');tb.className='grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded bg-gray-100';thumb(f,tb);
    var nm=document.createElement('span');nm.className='min-w-0 flex-1 truncate';nm.textContent=(i+1)+'. '+f.name+' ('+Math.max(1,Math.round(f.size/1024))+' KB)';
    li.appendChild(tb);li.appendChild(nm);
    [['↑',-1],['↓',1]].forEach(function(b){var x=document.createElement('button');x.type='button';x.textContent=b[0];x.className='rounded border px-2 py-1';x.onclick=function(){var j=i+b[1];if(j<0||j>=files.length)return;var t=files[i];files[i]=files[j];files[j]=t;sync();};li.appendChild(x);});
    var d=document.createElement('button');d.type='button';d.textContent='✕';d.className='rounded border border-red-200 px-2 py-1 text-red-600';d.onclick=function(){files.splice(i,1);sync();};li.appendChild(d);list.appendChild(li);});}
  picker.addEventListener('change',function(){add(picker.files);picker.value='';});
  ['dragover','dragenter'].forEach(function(e){drop.addEventListener(e,function(ev){ev.preventDefault();drop.classList.add('border-indigo-500');});});
  ['dragleave','drop'].forEach(function(e){drop.addEventListener(e,function(){drop.classList.remove('border-indigo-500');});});
  drop.addEventListener('drop',function(ev){ev.preventDefault();add(ev.dataTransfer.files);});
  var form=real.closest('form');if(form){form.addEventListener('submit',function(ev){var m=parseInt(real.getAttribute('data-min')||'0',10);if(files.length<m){ev.preventDefault();alert('Please add at least '+m+' file(s).');}},true);}
})();
</script>
