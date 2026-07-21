export interface Permission {
    id: number;
    name: string;
    display_name: string;
}
export interface Role {
    id: number;
    name: string;
    display_name: string;
    permissions?: Permission[];
}
export interface User {
    id: number;
    first_name: string;
    last_name: string;
    username: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    role_id: number;
    role?: Role;
}
