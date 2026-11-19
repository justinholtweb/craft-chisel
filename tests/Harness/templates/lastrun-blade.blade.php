{{-- Chisel harness: the read-only template helper. --}}
@php($r = $chisel->lastRun('chisel:probe'))
<p id="can">{{ $chisel->canView() ? 'yes' : 'no' }}</p>
<p id="last">{{ $r ? $r['command'].'/'.$r['status'].'/'.($r['exitCode'] ?? '-') : 'none' }}</p>
<p id="keys">{{ $r ? implode(',', array_keys($r)) : '' }}</p>
