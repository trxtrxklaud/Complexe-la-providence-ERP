import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { apiFetch } from '../api/http';
import { useAuth } from './AuthContext';
import type { AcademicYear, Level, Section } from '../types';
import type { FeeType } from '../api/feeTypes';

interface MasterData {
  academicYears: AcademicYear[];
  levels: Level[];
  sections: Section[];
  feeTypes: FeeType[];
}

interface MasterDataContextType {
  data: MasterData;
  loading: boolean;
  refreshMasterData: () => Promise<void>;
}

const defaultMasterData: MasterData = {
  academicYears: [],
  levels: [],
  sections: [],
  feeTypes: [],
};

const MasterDataContext = createContext<MasterDataContextType | undefined>(undefined);

export function MasterDataProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const [data, setData] = useState<MasterData>(defaultMasterData);
  const [loading, setLoading] = useState<boolean>(false);

  const fetchBootstrap = useCallback(async (force = false) => {
    if (!user) return;
    setLoading(true);
    try {
      const [yearsRes, levelsRes, feeTypesRes] = await Promise.all([
        apiFetch<AcademicYear[] | { data: AcademicYear[] }>('/academic-years', { forceRefresh: force }).catch(() => []),
        apiFetch<Level[]>('/levels', { forceRefresh: force }).catch(() => []),
        apiFetch<FeeType[]>('/fee-types', { forceRefresh: force }).catch(() => []),
      ]);

      const academicYears = Array.isArray(yearsRes) ? yearsRes : (yearsRes && 'data' in yearsRes ? (yearsRes as { data: AcademicYear[] }).data : []);
      const levels = Array.isArray(levelsRes) ? levelsRes : [];
      const sections = levels.flatMap((l: any) => l.sections || []);
      const feeTypes = Array.isArray(feeTypesRes) ? feeTypesRes : [];

      setData({
        academicYears,
        levels,
        sections,
        feeTypes,
      });
    } catch (err) {
      console.error('Failed to bootstrap master data:', err);
    } finally {
      setLoading(false);
    }
  }, [user]);

  useEffect(() => {
    if (user) {
      fetchBootstrap();
    } else {
      setData(defaultMasterData);
    }
  }, [user, fetchBootstrap]);

  return (
    <MasterDataContext.Provider value={{ data, loading, refreshMasterData: () => fetchBootstrap(true) }}>
      {children}
    </MasterDataContext.Provider>
  );
}

export function useMasterData(): MasterDataContextType {
  const context = useContext(MasterDataContext);
  if (!context) {
    throw new Error('useMasterData must be used within a MasterDataProvider');
  }
  return context;
}
