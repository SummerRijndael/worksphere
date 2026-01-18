export interface NavigationItem {
  id: string;
  label: string;
  icon?: string;
  route?: string;
  type?: 'divider' | 'item';
  pinned?: boolean;
  pinnable?: boolean;
  badge?: number | string;
  badge_key?: string;
  children?: NavigationChild[];
}

export interface NavigationChild {
  id: string;
  label: string;
  route: string;
  badge?: number | string;
}

export interface NavigationPreferences {
  pinned_items?: string[];
  pinnedItems?: string[];
}

export interface NavigationBadges {
  [key: string]: number | undefined;
}

export interface NavigationResponse {
  sidebar: NavigationItem[];
  badges?: NavigationBadges;
  preferences?: NavigationPreferences;
}
