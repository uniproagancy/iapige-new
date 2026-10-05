@props(['name', 'size' => null])

<svg {{ $attributes->class(['ic', 'ic-'.$size => $size]) }} aria-hidden="true"><use href="#i-{{ $name }}"/></svg>
