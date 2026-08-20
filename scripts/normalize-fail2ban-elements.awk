function reset_table_state() {
    in_fail2ban = ($0 == "table inet f2b-table {")
    in_elements = 0
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

in_fail2ban && /^[[:space:]]*elements[[:space:]]*=/ {
    print "\t\telements = { DYNAMIC_BANS }"
    in_elements = ($0 !~ /}/)
    next
}

{
    print
}
