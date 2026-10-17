export type User = {
    id: number;
    name: string;
    username: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type TeamRole = 'owner' | 'admin' | 'member';

export type Team = {
    id: number;
    name: string;
    role: TeamRole;
};

export type Auth = {
    user: User;
    currentTeam: Team;
    teams: Team[];
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
