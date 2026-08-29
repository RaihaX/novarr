{{--
    Include-style wrapper around the <x-icon> component, for templates that
    prefer @include. Prefer `<x-icon name="…" :size="…" />` where you can —
    component props are scoped, whereas @include inherits the caller's
    variables, so an omitted $size here picks up whatever the caller happens
    to have in scope.

    Usage:
        @include('partials.icon', ['name' => 'search', 'size' => 14])
--}}
<x-icon :name="$name"
        :size="$size ?? 16"
        :stroke="$stroke ?? 2"
        :class="$class ?? 'icon'" />
