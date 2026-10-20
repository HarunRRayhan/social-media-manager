import { Link, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Layers,
    LayoutGrid,
    Plug,
    Settings,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as editApiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { index as automations } from '@/routes/automations';
import { index as calendar } from '@/routes/calendar';
import { index as connections } from '@/routes/connections';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as socialSets } from '@/routes/social-sets';
import { edit as editTeam } from '@/routes/team';
import { index as teamMembers } from '@/routes/team-members';
import type { NavGroup, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Calendar', href: calendar(), icon: CalendarDays },
    { title: 'Connections', href: connections(), icon: Plug },
    { title: 'Social sets', href: socialSets(), icon: Layers },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const canManage = auth.currentTeam?.role !== 'member';

    const groups: NavGroup[] = [
        {
            title: 'Team',
            icon: Users,
            items: [
                { title: 'General', href: editTeam() },
                { title: 'Members', href: teamMembers() },
                { title: 'Automations', href: automations() },
            ],
        },
        {
            title: 'Settings',
            icon: Settings,
            items: [
                { title: 'Profile', href: editProfile() },
                { title: 'Security', href: editSecurity() },
                { title: 'Appearance', href: editAppearance() },
                // Only owners and admins manage tokens.
                ...(canManage
                    ? [{ title: 'API tokens', href: editApiTokens() }]
                    : []),
            ],
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} groups={groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
