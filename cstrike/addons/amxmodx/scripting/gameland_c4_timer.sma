/*
 * CSB C4 Timer
 * Copyright (C) 2026 counter-strike-boost.com
 *
 * Once the bomb is planted this shows a live HUD countdown of the exact seconds
 * left, based on mp_c4timer and the moment of the plant. The colour ramps from
 * green to red as the fuse burns down, and each CT is told whether they can
 * still reach and defuse it in time given their defuse kit.
 *
 * Inspired by the classic "C4 Timer" plugins for AMX Mod X. This is an
 * independent GPL re-implementation; no original code is reused.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version. It is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details: <https://www.gnu.org/licenses/>.
 */

#include <amxmodx>
#include <cstrike>

new const PLUGIN[] = "CSB C4 Timer"
new const VERSION[] = "1.0.0"
new const AUTHOR[]  = "counter-strike-boost.com"

/* stock defuse timings without / with a defuse kit */
#define DEFUSE_NOKIT  10.0
#define DEFUSE_KIT    5.0

new g_pEnabled, g_pBeep
new g_pC4Timer

new bool:g_bPlanted = false
new Float:g_flExplodeAt
new g_iSync

public plugin_init()
{
    register_plugin(PLUGIN, VERSION, AUTHOR)

    g_pEnabled = register_cvar("csb_c4timer_enabled", "1")
    g_pBeep    = register_cvar("csb_c4timer_beep", "1")

    g_pC4Timer = get_cvar_pointer("mp_c4timer")

    g_iSync = CreateHudSyncObj()

    register_logevent("evPlanted", 3, "2=Planted_The_Bomb")
    register_logevent("evDefused", 3, "2=Defused_The_Bomb")

    /* new round resets everything (HLTV new-round message) */
    register_event("HLTV", "evNewRound", "a", "1=0", "2=0")
    register_event("TextMsg", "evRestart", "a", "2=#Game_will_restart_in")
}

public evPlanted()
{
    if (!get_pcvar_num(g_pEnabled))
        return

    new Float:c4timer = g_pC4Timer ? get_pcvar_float(g_pC4Timer) : 45.0

    if (c4timer <= 0.0)
        c4timer = 45.0

    g_bPlanted = true
    g_flExplodeAt = get_gametime() + c4timer

    set_task(0.2, "taskTick", 0, _, _, "b")
}

public evDefused()
{
    stopTimer()
}

public evNewRound()
{
    stopTimer()
}

public evRestart()
{
    stopTimer()
}

stopTimer()
{
    if (!g_bPlanted)
        return

    g_bPlanted = false
    remove_task(0)
    ClearSyncHud(0, g_iSync)
}

public taskTick()
{
    if (!g_bPlanted)
    {
        remove_task(0)
        return
    }

    new Float:remain = g_flExplodeAt - get_gametime()

    if (remain <= 0.0)
    {
        stopTimer()
        return
    }

    new r, g, b
    fuseColor(remain, r, g, b)

    /* the bomb is a shared threat - draw it to everyone, then add the
       personal defuse note only for living CTs */
    new players[32], num, pid
    get_players(players, num, "ch")

    new bars[16]
    beepBars(remain, bars, charsmax(bars))

    for (new i = 0; i < num; i++)
    {
        pid = players[i]

        new note[64]
        note[0] = 0

        if (is_user_alive(pid) && cs_get_user_team(pid) == CS_TEAM_CT)
            defuseNote(pid, remain, note, charsmax(note))

        set_hudmessage(r, g, b, 0.02, 0.20, 0, 0.0, 0.3, 0.0, 0.0, -1)

        if (note[0])
            ShowSyncHudMsg(pid, g_iSync, "C4: %.1f  %s^n%s", remain, bars, note)
        else
            ShowSyncHudMsg(pid, g_iSync, "C4: %.1f  %s", remain, bars)
    }
}

fuseColor(Float:remain, &r, &g, &b)
{
    if (remain <= 5.0)       { r = 255; g = 0;   b = 0;   }
    else if (remain <= 15.0) { r = 255; g = 140; b = 0;   }
    else                     { r = 0;   g = 220; b = 0;   }
}

beepBars(Float:remain, out[], len)
{
    if (!get_pcvar_num(g_pBeep))
    {
        out[0] = 0
        return
    }

    /* more filled bars the closer the explosion is */
    new filled

    if (remain <= 5.0)       filled = 5
    else if (remain <= 10.0) filled = 4
    else if (remain <= 20.0) filled = 3
    else if (remain <= 30.0) filled = 2
    else                     filled = 1

    new p = 0

    for (new i = 0; i < 5 && p < len - 1; i++)
        out[p++] = (i < filled) ? '|' : '.'

    out[p] = 0
}

defuseNote(id, Float:remain, out[], len)
{
    new Float:need = cs_get_user_defuse(id) ? DEFUSE_KIT : DEFUSE_NOKIT

    if (remain >= need)
        formatex(out, len, "Defusable (%.0fs needed)", need)
    else
        formatex(out, len, "TOO LATE to defuse")
}
