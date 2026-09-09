function reset_table_state() {
    in_fail2ban = ($0 == "table inet f2b-table {")
    in_set = 0
    in_elements = 0
    emitted_elements = 0
}

/^table / {
    reset_table_state()
}

in_fail2ban && in_elements {
    if ($0 ~ /}/) {
        in_elements = 0
    }

    next
}

in_fail2ban && /^[[:space:]]*set[[:space:]].*\{$/ {
    in_set = 1
    emitted_elements = 0
    print
    next
}

in_fail2ban && /^[[:space:]]*elements[[:space:]]*=/ {
    print "\t\telements = { DYNAMIC_BANS }"
    emitted_elements = 1
    in_elements = ($0 !~ /}/)
    next
}

in_fail2ban && in_set && /^[[:space:]]*}/ {
    if (!emitted_elements) {
        print "\t\telements = { DYNAMIC_BANS }"
    }

    in_set = 0
    print
    next
}

{
    print
}
